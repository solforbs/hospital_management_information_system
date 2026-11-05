"""End to end over real sockets: simulator -> gateway driver -> fake HMIS API."""

import json
import os
import socket
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = os.path.join(os.path.dirname(__file__), "..")
sys.path.insert(0, ROOT)

from hmis_gateway.client import HmisClient, Spool, flush  # noqa: E402
from hmis_gateway.drivers import make_driver, serve  # noqa: E402
from hmis_gateway.links import connections  # noqa: E402

ORDER = {"sample_id": "SPM-000777", "priority": "routine", "collected_at": "2026-11-05T09:00:00+03:00", "specimen_type": "blood",
         "test": {"code": "RBS", "name": "Random blood sugar"}, "instrument_codes": ["GLU"],
         "patient": {"number": "MRN-9", "last_name": "Kamau", "first_name": "John", "sex": "M", "date_of_birth": "1980-01-02"}}


class FakeHmis(BaseHTTPRequestHandler):
    results: list = []
    up = True

    def _send(self, status, body):
        data = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):  # noqa: N802
        if self.headers.get("Authorization") != "Bearer hgw_test":
            return self._send(401, {"message": "bad token"})
        if self.path.startswith("/api/lis/v1/orders/SPM-000777"):
            return self._send(200, {"orders": [ORDER]})
        if self.path.startswith("/api/lis/v1/orders"):
            return self._send(200, {"orders": []})
        self._send(404, {"message": "nope"})

    def do_POST(self):  # noqa: N802
        if not FakeHmis.up:
            return self._send(503, {"message": "down for maintenance"})
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        if self.path.endswith("/results"):
            FakeHmis.results.append(body)
            return self._send(200, {"status": "accepted", "detail": "ok"})
        self._send(200, {"gateway": {"name": "t", "hospital": "h"}, "instruments": []})

    def log_message(self, *args):
        pass


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class EndToEndTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.http = ThreadingHTTPServer(("127.0.0.1", 0), FakeHmis)
        threading.Thread(target=cls.http.serve_forever, daemon=True).start()
        cls.client = HmisClient(f"http://127.0.0.1:{cls.http.server_port}/api/lis/v1", "hgw_test")

    @classmethod
    def tearDownClass(cls):
        cls.http.shutdown()

    def setUp(self):
        FakeHmis.results = []
        FakeHmis.up = True
        self.tmp = tempfile.mkdtemp()
        self.spool = Spool(os.path.join(self.tmp, "spool.db"))
        self.stop = threading.Event()

    def tearDown(self):
        self.stop.set()

    def start_gateway(self, protocol: str, bidirectional: bool = False) -> int:
        port = free_port()
        config = {"code": "AN1", "protocol": protocol, "transport": "tcp-server", "listen": "127.0.0.1", "port": port, "bidirectional": bidirectional}
        driver = make_driver(config, self.client, self.spool)
        threading.Thread(target=serve, args=(driver, connections(config, self.stop), self.stop), daemon=True).start()
        time.sleep(0.3)
        return port

    def simulate(self, *args) -> str:
        out = subprocess.run([sys.executable, os.path.join(ROOT, "simulator.py"), *args], capture_output=True, text=True, timeout=60)
        self.assertEqual(0, out.returncode, out.stderr)
        return out.stdout

    def wait_for_spool(self, n: int):
        deadline = time.time() + 10
        while self.spool.pending_count() < n and time.time() < deadline:
            time.sleep(0.1)

    def test_hl7_fbc_reaches_hmis(self):
        port = self.start_gateway("hl7")
        reply = self.simulate("--protocol", "hl7", "--port", str(port), "--sample", "SPM-000123", "--panel", "fbc")
        self.assertIn("MSA|AA", reply)
        self.wait_for_spool(1)
        self.assertEqual(1, flush(self.client, self.spool))

        sent = FakeHmis.results[0]
        self.assertEqual(("AN1", "SPM-000123"), (sent["instrument"], sent["sample_id"]))
        codes = [r["code"] for r in sent["results"]]
        self.assertEqual(["WBC", "LYM#", "GRAN#", "RBC", "HGB", "HCT", "MCV", "PLT"], codes)  # histogram skipped
        self.assertEqual({"code": "PLT", "value": "512", "unit": "10*9/L", "reference_range": "100-300", "flag": "H"}, sent["results"][-1])

    def test_astm_results_survive_an_outage(self):
        port = self.start_gateway("astm")
        self.simulate("--protocol", "astm", "--port", str(port), "--sample", "SPM-000124", "--panel", "renal")
        self.wait_for_spool(1)

        FakeHmis.up = False
        self.assertEqual(0, flush(self.client, self.spool))
        self.assertEqual(1, self.spool.pending_count(), "kept in the spool while HMIS is down")

        FakeHmis.up = True
        self.spool.db.execute("UPDATE outbox SET next_try = 0")  # skip the back-off for the test
        self.assertEqual(1, flush(self.client, self.spool))
        self.assertEqual(0, self.spool.pending_count())
        self.assertEqual(["CREA", "UREA", "NA", "K"], [r["code"] for r in FakeHmis.results[0]["results"]])

    def test_astm_barcode_query_gets_the_order(self):
        port = self.start_gateway("astm", bidirectional=True)
        reply = self.simulate("--protocol", "astm", "--port", str(port), "--query", "SPM-000777")
        self.assertIn("O|1|SPM-000777||^^^GLU|R", reply)
        self.assertIn("P|1|MRN-9|||Kamau^John||19800102|M", reply)
        self.assertTrue(self.spool.was_sent("AN1", "SPM-000777"))

    def test_unknown_query_gets_no_information(self):
        port = self.start_gateway("astm", bidirectional=True)
        reply = self.simulate("--protocol", "astm", "--port", str(port), "--query", "SPM-404")
        self.assertIn("L|1|I", reply)


if __name__ == "__main__":
    unittest.main()
