import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from hmis_gateway import astm, hl7  # noqa: E402


class AstmTest(unittest.TestCase):
    def test_checksum_and_frame_roundtrip(self):
        frames = astm.build_frames("R|1|^^^GLU|5.6|mmol/L|3.9-7.8|N||F")
        self.assertEqual(1, len(frames))
        frame = astm.parse_frame(frames[0])
        self.assertEqual(1, frame.number)
        self.assertTrue(frame.final)
        self.assertEqual(b"R|1|^^^GLU|5.6|mmol/L|3.9-7.8|N||F\r", frame.text)
        # Known E1381 example: STX '1' 'H|\^&' CR ETX -> checksum over '1H|\^&\r\x03'
        body = b"1H|\\^&\r\x03"
        self.assertEqual(f"{sum(body) % 256:02X}".encode(), astm.checksum(body))

    def test_bad_checksum_is_rejected(self):
        frame = bytearray(astm.build_frames("H|\\^&")[0])
        frame[-4:-2] = b"00" if frame[-4:-2] != b"00" else b"01"
        with self.assertRaises(astm.FrameError):
            astm.parse_frame(bytes(frame))

    def test_long_records_split_and_frame_numbers_wrap(self):
        long = "C|1|L|" + "x" * 1000
        frames = astm.build_message_frames(["H|\\^&"] + [long] * 2)
        parsed = [astm.parse_frame(f) for f in frames]
        self.assertEqual([1, 2, 3, 4, 5, 6, 7, 0, 1, 2, 3][:len(parsed)], [p.number for p in parsed])
        self.assertFalse(parsed[1].final)  # ETB: record continues
        text = b"".join(p.text for p in parsed[1:6]).decode()
        self.assertEqual(long + "\r", text)

    def test_parse_results_message(self):
        records = [
            "H|\\^&|||Cobas c111^1|||||||P|1|20261105101500",
            "P|1||PAT001||Otieno^Akinyi||19900501|F",
            "O|1|SPM-000123||^^^GLU\\^^^CREA|R||||||N||||||||||||||F",
            "R|1|^^^GLU^1|11.8|mmol/L|3.9 to 7.8|H||F||||20261105101400",
            "R|2|^^^CREA|96|umol/L|60-110|N||F",
            "R|3|^^^ALT|||U/L|||X",  # could not be done -> ignored
            "C|1|I|Sample slightly haemolysed|G",
            "L|1|N",
        ]
        msg = astm.parse_message(records)
        self.assertEqual(["SPM-000123"], list(msg.results))
        self.assertEqual(["GLU", "CREA"], [r.code for r in msg.results["SPM-000123"]])
        glu = msg.results["SPM-000123"][0].as_api()
        self.assertEqual({"code": "GLU", "value": "11.8", "unit": "mmol/L", "reference_range": "3.9 to 7.8", "flag": "H"}, glu)

    def test_custom_delimiters_and_escapes(self):
        # Header H!@#$ declares field '!', repeat '@', component '#', escape '$'.
        records = ["H!@#$", "O!1!S-1", "R!1!###NA!139#1!mmol", "R!2!###K!4.1!mmol$S$L"]
        msg = astm.parse_message(records)
        na, k = msg.results["S-1"]
        self.assertEqual(("NA", "139"), (na.code, na.value))  # first component of '139#1'
        self.assertEqual("mmol#L", k.unit)  # escaped component separator is data, not a split

    def test_query_and_order_reply(self):
        msg = astm.parse_message(["H|\\^&", "Q|1|^SPM-7\\^SPM-8||^^^ALL||||||||O", "L|1|N"])
        self.assertEqual(["SPM-7", "SPM-8"], msg.queries)

        records = astm.build_order_records([{
            "sample_id": "SPM-7", "priority": "stat", "collected_at": "2026-11-05T10:00:00+03:00", "specimen_type": "blood",
            "instrument_codes": ["GLU", "CREA"],
            "patient": {"number": "MRN-1", "last_name": "Otieno", "first_name": "Akinyi", "sex": "F", "date_of_birth": "1990-05-01"},
        }])
        self.assertTrue(records[0].startswith("H|\\^&"))
        p = records[1].split("|")
        self.assertEqual(["P", "1", "MRN-1"], p[:3])
        self.assertEqual("Otieno^Akinyi", p[5])
        self.assertEqual("19900501", p[7])
        o = records[2].split("|")
        self.assertEqual("SPM-7", o[2])
        self.assertEqual("^^^GLU\\^^^CREA", o[4])
        self.assertEqual("S", o[5])
        self.assertEqual("O", o[25])
        self.assertEqual("L|1|N", records[-1])
        # Every record survives framing and checksum validation.
        self.assertEqual(records, [r for f in astm.build_message_frames(records) for r in astm.parse_frame(f).text.decode().split("\r") if r])


class Hl7Test(unittest.TestCase):
    MINDRAY_ORU = "\r".join([
        "MSH|^~\\&|BC-5150|Mindray|||20261105101500||ORU^R01|1042|P|2.3.1||||||UNICODE",
        "PID|1||^^^^MR||^",
        "OBR|1||SPM-000123|00001^Automated Count^99MRC|||20261105101400",
        "OBX|1|IS|08001^Take Mode^99MRC||O||||||F",
        "OBX|2|NM|6690-2^WBC^LN||6.20|10*9/L|4.00-10.00|N|||F",
        "OBX|3|NM|718-7^HGB^LN||18.9|g/dL|12.0-16.0|H~A|||F",
        "OBX|4|NM|777-3^PLT^LN||250|10*9/L|150-400|N|||F",
        "OBX|5|ED|15051^PLT Histogram^99MRC||^Image^BMP^Base64^Qk0=||||||F",
    ]) + "\r"

    def test_mllp_framing_handles_partial_buffers(self):
        data = hl7.frame("MSH|^~\\&|A|B|||1||ORU^R01|1|P|2.3.1") + hl7.frame("MSH|^~\\&|A|B|||1||ORU^R01|2|P|2.3.1")
        msgs, rest = hl7.unframe(data[:10])
        self.assertEqual([], msgs)
        msgs, rest = hl7.unframe(data)
        self.assertEqual(2, len(msgs))
        self.assertEqual(b"", rest)

    def test_parse_mindray_oru(self):
        groups = hl7.parse_oru(hl7.parse(self.MINDRAY_ORU))
        self.assertEqual(1, len(groups))
        self.assertEqual("SPM-000123", groups[0].sample_id)
        codes = [r.code for r in groups[0].results]
        self.assertEqual(["WBC", "HGB", "PLT"], codes)  # Take Mode (IS) and histogram (ED) skipped
        hgb = next(r for r in groups[0].results if r.code == "HGB").as_api()
        self.assertEqual({"code": "HGB", "value": "18.9", "unit": "g/dL", "reference_range": "12.0-16.0", "flag": "H"}, hgb)
        self.assertNotIn("PLT Histogram", codes)

    def test_code_component_modes(self):
        self.assertEqual("WBC", hl7.result_code("6690-2^WBC^LN"))
        self.assertEqual("GLU", hl7.result_code("GLU^Glucose^99LOC"))
        self.assertEqual("Glucose", hl7.result_code("GLU^Glucose^99LOC", mode="second"))

    def test_ack_and_order_message(self):
        msg = hl7.parse(self.MINDRAY_ORU)
        ack = hl7.ack(msg)
        self.assertIn("ACK^R01|1042|P|2.3.1", ack)
        self.assertIn("MSA|AA|1042", ack)
        orm = hl7.parse(hl7.order_message({"sample_id": "SPM-9", "priority": "stat", "instrument_codes": ["WBC", "HGB"],
                                           "patient": {"number": "MRN-1", "last_name": "Otieno", "first_name": "A", "sex": "F", "date_of_birth": "1990-05-01"}}, "C1"))
        self.assertEqual("ORM^O01", orm.type)
        self.assertEqual("SPM-9", orm.field("OBR", 3))
        self.assertEqual("S", orm.field("OBR", 5))


if __name__ == "__main__":
    unittest.main()
