"""HL7 v2.x over MLLP, as used by many haematology and chemistry analysers.

Pure functions only: no I/O.

MLLP frame: VT (0x0B) + message + FS (0x1C) + CR. Segments are separated by CR.
Results arrive as ORU^R01 (PID, OBR, OBX...). Each message is acknowledged
with an ACK (MSA|AA|<control id>).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime

VT, FS, CR = b"\x0b", b"\x1c", b"\r"

# OBX value types that are not results: embedded data (histograms, scattergrams),
# references, and coded settings such as Mindray's "Take Mode" (IS).
SKIP_VALUE_TYPES = {"ED", "RP", "IS"}


def frame(message: str) -> bytes:
    return VT + message.replace("\n", "\r").encode("utf-8") + FS + CR


def unframe(buffer: bytes) -> tuple[list[str], bytes]:
    """Extracts complete MLLP messages from a byte buffer; returns (messages, remainder)."""
    messages = []
    while True:
        start = buffer.find(VT)
        if start == -1:
            return messages, b""
        end = buffer.find(FS, start)
        if end == -1:
            return messages, buffer[start:]
        messages.append(buffer[start + 1:end].decode("utf-8", errors="replace"))
        buffer = buffer[end + 1:]
        if buffer.startswith(CR):
            buffer = buffer[1:]


@dataclass
class Hl7Message:
    segments: list[list[str]]
    component: str = "^"
    repeat: str = "~"

    @property
    def type(self) -> str:
        return self.field("MSH", 9).replace(self.component, "^")

    @property
    def control_id(self) -> str:
        return self.field("MSH", 10)

    def segment(self, name: str) -> list[str] | None:
        return next((s for s in self.segments if s and s[0] == name), None)

    def field(self, name: str, index: int) -> str:
        seg = self.segment(name)
        if seg is None:
            return ""
        # MSH-1 is the field separator itself, so MSH fields are shifted by one.
        i = index - 1 if name == "MSH" else index
        return seg[i] if 0 <= i < len(seg) else ""


def parse(message: str) -> Hl7Message:
    lines = [line for line in message.replace("\n", "\r").split("\r") if line.strip()]
    if not lines or not lines[0].startswith("MSH"):
        raise ValueError("not an HL7 message (no MSH)")
    field_sep = lines[0][3]
    encoding = lines[0][4:8] if len(lines[0]) >= 8 else "^~\\&"
    segments = [line.split(field_sep) for line in lines]
    return Hl7Message(segments=segments, component=encoding[0], repeat=encoding[1])


@dataclass
class Hl7Result:
    code: str
    value: str
    unit: str = ""
    reference_range: str = ""
    flag: str = ""

    def as_api(self) -> dict:
        return {"code": self.code, "value": self.value, "unit": self.unit or None,
                "reference_range": self.reference_range or None, "flag": self.flag or None}


@dataclass
class OruResults:
    sample_id: str
    results: list[Hl7Result] = field(default_factory=list)


def result_code(identifier: str, comp: str = "^", mode: str = "auto") -> str:
    """OBX-3 -> analyser code.

    auto: '6690-2^WBC^LN' (LOINC first, short name second) -> 'WBC';
          'WBC^^99MRC' or 'GLU^Glucose' -> first component.
    'first' / 'second' force a component.
    """
    parts = [p.strip() for p in identifier.split(comp)]
    if mode == "second" and len(parts) > 1 and parts[1]:
        return parts[1]
    if mode == "auto" and len(parts) > 2 and parts[2].upper() in ("LN", "LOINC") and parts[1]:
        return parts[1]
    return next((p for p in parts if p), "")


def parse_oru(msg: Hl7Message, code_mode: str = "auto") -> list[OruResults]:
    """Results grouped by sample (OBR-3 filler order number, else OBR-2 placer number)."""
    out: list[OruResults] = []
    current: OruResults | None = None
    c = msg.component

    for seg in msg.segments:
        name = seg[0]
        if name == "OBR":
            sample = (seg[3] if len(seg) > 3 else "").split(c)[0].strip() or (seg[2] if len(seg) > 2 else "").split(c)[0].strip()
            current = OruResults(sample_id=sample)
            out.append(current)
        elif name == "OBX" and current is not None:
            g = lambda i: seg[i] if i < len(seg) else ""  # noqa: E731
            value_type = g(2).upper()
            status = g(11).strip().upper()
            value = g(5).split(c)[0].strip() if value_type != "SN" else "".join(g(5).split(c)).strip()
            code = result_code(g(3), c, code_mode)
            if value_type in SKIP_VALUE_TYPES or not code or not value or status in ("X", "D", "W"):
                continue
            current.results.append(Hl7Result(code=code, value=value, unit=g(6).split(c)[0].strip(),
                                             reference_range=g(7).strip(), flag=g(8).split(msg.repeat)[0].strip()))

    return [r for r in out if r.sample_id and r.results]


def _ts() -> str:
    return datetime.now().strftime("%Y%m%d%H%M%S")


def ack(msg: Hl7Message, code: str = "AA", text: str = "") -> str:
    """ACK for a received message (AA accept, AE error, AR reject)."""
    version = msg.field("MSH", 12) or "2.3.1"
    trigger = msg.type.split("^")[1] if "^" in msg.type else ""
    return "\r".join([
        f"MSH|^~\\&|HMIS|HMIS|{msg.field('MSH', 3)}|{msg.field('MSH', 4)}|{_ts()}||ACK^{trigger}|{msg.control_id}|P|{version}",
        f"MSA|{code}|{msg.control_id}" + (f"|{text}" if text else ""),
    ]) + "\r"


def order_message(order: dict, control_id: str, receiver: str = "") -> str:
    """ORM^O01 new order for one sample (bidirectional analysers)."""
    p = order.get("patient", {})
    dob = (p.get("date_of_birth") or "").replace("-", "")
    tests = "~".join(f"{code}^^99LOC" for code in order.get("instrument_codes", []))
    priority = {"stat": "S", "urgent": "A"}.get(order.get("priority", ""), "R")
    return "\r".join([
        f"MSH|^~\\&|HMIS|HMIS|{receiver}||{_ts()}||ORM^O01|{control_id}|P|2.3.1",
        f"PID|1||{p.get('number', '')}||{p.get('last_name', '')}^{p.get('first_name', '')}||{dob}|{p.get('sex', 'U')}",
        f"ORC|NW|{order['sample_id']}|||||||{_ts()}",
        f"OBR|1|{order['sample_id']}|{order['sample_id']}|{tests}|{priority}||||||||||{order.get('specimen_type', '')}",
    ]) + "\r"
