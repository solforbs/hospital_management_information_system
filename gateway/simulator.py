"""Analyser simulator: sends results to a running gateway like a real analyser would.

    python simulator.py --protocol hl7  --port 5100 --sample SPM-000123 --panel fbc
    python simulator.py --protocol astm --port 5200 --sample SPM-000124 --panel glucose
    python simulator.py --protocol astm --port 5200 --query SPM-000125      (barcode query)

Use it to test a gateway installation before connecting the real analyser.
"""

from __future__ import annotations

import argparse
import socket
import sys
import time
from datetime import datetime

sys.path.insert(0, __file__.rsplit("simulator.py", 1)[0])

from hmis_gateway import astm, hl7  # noqa: E402

PANELS = {
    # code, value, unit, range, flag
    "fbc": [("WBC", "6.20", "10^9/L", "4.00-10.00", "N"), ("LYM#", "2.10", "10^9/L", "0.80-4.00", "N"),
            ("GRAN#", "3.50", "10^9/L", "2.00-7.00", "N"), ("RBC", "4.85", "10^12/L", "3.50-5.50", "N"),
            ("HGB", "13.4", "g/dL", "11.0-16.0", "N"), ("HCT", "40.1", "%", "37.0-54.0", "N"),
            ("MCV", "82.7", "fL", "80.0-100.0", "N"), ("PLT", "512", "10^9/L", "100-300", "H")],
    "glucose": [("GLU", "11.8", "mmol/L", "3.9-7.8", "H")],
    "renal": [("CREA", "96", "umol/L", "60-110", "N"), ("UREA", "5.1", "mmol/L", "2.5-7.8", "N"),
              ("NA", "139", "mmol/L", "135-145", "N"), ("K", "5.9", "mmol/L", "3.5-5.1", "H")],
}


def now() -> str:
    return datetime.now().strftime("%Y%m%d%H%M%S")


def read_byte(sock: socket.socket, timeout: float = 15) -> bytes:
    sock.settimeout(timeout)
    return sock.recv(1)


def astm_send(sock: socket.socket, records: list[str]) -> None:
    sock.sendall(astm.ENQ)
    assert read_byte(sock) == astm.ACK, "gateway did not ACK our ENQ"
    for frame in astm.build_message_frames(records):
        sock.sendall(frame)
        assert read_byte(sock) == astm.ACK, "gateway rejected a frame"
    sock.sendall(astm.EOT)


def astm_receive(sock: socket.socket) -> list[str]:
    """Receive the host's reply (orders) after a query."""
    assert read_byte(sock, 30) == astm.ENQ, "expected ENQ from gateway"
    sock.sendall(astm.ACK)
    buffer, records, partial = b"", [], b""
    while True:
        chunk = sock.recv(4096)
        buffer += chunk
        while buffer:
            if buffer[:1] == astm.EOT:
                return records
            end = min((p for p in (buffer.find(astm.ETX), buffer.find(astm.ETB)) if p != -1), default=-1)
            if buffer[:1] != astm.STX or end == -1 or len(buffer) < end + 5:
                break
            frame, buffer = astm.parse_frame(buffer[:end + 5]), buffer[end + 5:]
            sock.sendall(astm.ACK)
            partial += frame.text
            if frame.final:
                records += [r for r in partial.decode("latin-1").split("\r") if r]
                partial = b""


def astm_results(sample: str, panel: str) -> list[str]:
    records = [f"H|\\^&|||SimAnalyser^1.0|||||||P|1|{now()}", "P|1", f"O|1|{sample}||^^^{panel.upper()}|R||||||N||||||||||||||F"]
    for n, (code, value, unit, rng, flag) in enumerate(PANELS[panel], start=1):
        records.append(f"R|{n}|^^^{code}|{value}|{unit}|{rng}|{flag}||F||||{now()}")
    records.append("L|1|N")
    return records


def hl7_results(sample: str, panel: str) -> str:
    segs = [f"MSH|^~\\&|SimAnalyser|LAB|HMIS|HOSP|{now()}||ORU^R01|{int(time.time())}|P|2.3.1",
            "PID|1||||^||",
            f"OBR|1||{sample}|00001^Automated Count^99MRC|||{now()}"]
    for n, (code, value, unit, rng, flag) in enumerate(PANELS[panel], start=1):
        # '^' is HL7's component separator, so analysers write 10^9/L as 10*9/L.
        segs.append(f"OBX|{n}|NM|{code}^{code}^99MRC||{value}|{unit.replace('^', '*')}|{rng}|{flag}|||F")
    segs.append("OBX|99|ED|PLT Histogram^^99MRC||^Image^BMP^Base64^Qk0=||||||F")  # ignored by the gateway
    return "\r".join(segs) + "\r"


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--protocol", choices=["astm", "hl7"], required=True)
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--port", type=int, required=True)
    p.add_argument("--sample", help="specimen number printed on the tube's barcode")
    p.add_argument("--panel", choices=sorted(PANELS), default="fbc")
    p.add_argument("--query", help="ASTM only: ask the host what to run on this sample")
    a = p.parse_args()

    with socket.create_connection((a.host, a.port), timeout=15) as sock:
        if a.query:
            astm_send(sock, [f"H|\\^&|||SimAnalyser|||||||P|1|{now()}", f"Q|1|^{a.query}||^^^ALL||||||||O", "L|1|N"])
            print("Host replied:\n  " + "\n  ".join(astm_receive(sock)))
        elif a.protocol == "astm":
            astm_send(sock, astm_results(a.sample, a.panel))
            print(f"Sent ASTM {a.panel} results for {a.sample}")
        else:
            sock.sendall(hl7.frame(hl7_results(a.sample, a.panel)))
            sock.settimeout(15)
            messages, _ = hl7.unframe(sock.recv(65536))
            print(f"Sent HL7 {a.panel} results for {a.sample}; gateway replied: {messages[0].splitlines()[-1] if messages else 'nothing'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
