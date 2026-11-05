"""ASTM E1381 (low-level framing) and E1394 (records), as used by LIS2-A2 analysers.

Pure functions only: no I/O. The session logic lives in drivers.py.

Low level (E1381):  ENQ -> ACK, then frames  STX FN text ETX|ETB C1 C2 CR LF  each
ACKed (NAK on a bad checksum), and EOT at the end. A record longer than one
frame is split over several ETB frames and finished by an ETX frame.
"""

from __future__ import annotations

from dataclasses import dataclass, field

ENQ, ACK, NAK, EOT = b"\x05", b"\x06", b"\x15", b"\x04"
STX, ETX, ETB, CR, LF = b"\x02", b"\x03", b"\x17", b"\r", b"\n"

MAX_FRAME_TEXT = 240  # E1381 allows 247 characters per frame including control characters


def checksum(body: bytes) -> bytes:
    """Two hex digits: sum of bytes from the frame number up to and including ETX/ETB, mod 256."""
    return f"{sum(body) % 256:02X}".encode("ascii")


def build_frames(record: str, start_number: int = 1) -> list[bytes]:
    """Splits one record (without its trailing CR) into E1381 frames."""
    text = (record + "\r").encode("latin-1")
    chunks = [text[i:i + MAX_FRAME_TEXT] for i in range(0, len(text), MAX_FRAME_TEXT)] or [b""]
    frames = []
    for n, chunk in enumerate(chunks):
        fn = str((start_number + n) % 8).encode("ascii")
        end = ETX if n == len(chunks) - 1 else ETB
        body = fn + chunk + end
        frames.append(STX + body + checksum(body) + CR + LF)
    return frames


def build_message_frames(records: list[str]) -> list[bytes]:
    """Frames for a whole message; frame numbers continue 1..7,0,1.. across records."""
    frames: list[bytes] = []
    for record in records:
        frames.extend(build_frames(record, start_number=len(frames) + 1))
    return frames


@dataclass
class Frame:
    number: int
    text: bytes
    final: bool  # ETX (end of record) vs ETB (record continues)


class FrameError(ValueError):
    pass


def parse_frame(raw: bytes) -> Frame:
    """Validates one received frame (STX ... CR LF) and returns its contents."""
    if not raw.startswith(STX):
        raise FrameError("frame does not start with STX")
    end_pos = max(raw.rfind(ETX), raw.rfind(ETB))
    if end_pos < 2:
        raise FrameError("no ETX/ETB in frame")
    body = raw[1:end_pos + 1]
    expected = raw[end_pos + 1:end_pos + 3]
    if checksum(body).upper() != expected.upper():
        raise FrameError(f"bad checksum: got {expected!r}, expected {checksum(body)!r}")
    if not body[:1].isdigit():
        raise FrameError("bad frame number")
    return Frame(number=int(body[:1]), text=body[1:-1], final=body[-1:] == ETX)


@dataclass
class Delimiters:
    field: str = "|"
    repeat: str = "\\"
    component: str = "^"
    escape: str = "&"

    @classmethod
    def from_header(cls, header: str) -> "Delimiters":
        # H|\^&  -> field '|', then repeat, component, escape.
        if len(header) >= 5 and header[0] == "H":
            return cls(field=header[1], repeat=header[2], component=header[3], escape=header[4])
        return cls()

    def unescape(self, value: str) -> str:
        e = self.escape
        return (value.replace(f"{e}F{e}", self.field).replace(f"{e}S{e}", self.component)
                .replace(f"{e}R{e}", self.repeat).replace(f"{e}E{e}", e))


@dataclass
class AstmResult:
    code: str
    value: str
    unit: str = ""
    reference_range: str = ""
    flag: str = ""
    status: str = ""

    def as_api(self) -> dict:
        return {"code": self.code, "value": self.value, "unit": self.unit or None,
                "reference_range": self.reference_range or None, "flag": self.flag or None}


@dataclass
class AstmMessage:
    """What an analyser sent in one ENQ..EOT transmission."""
    results: dict[str, list[AstmResult]] = field(default_factory=dict)  # sample id -> results
    queries: list[str] = field(default_factory=list)  # sample ids the analyser asked about
    records: list[str] = field(default_factory=list)


def _test_code(universal_id: str, d: Delimiters) -> str:
    """'^^^GLU^1' -> 'GLU'; 'GLU' -> 'GLU'. Splits on the raw field, then unescapes."""
    parts = universal_id.split(d.component)
    if len(parts) >= 4 and parts[3]:
        return d.unescape(parts[3]).strip()
    return next((d.unescape(p).strip() for p in parts if p.strip()), "")


def _first(value: str, d: Delimiters) -> str:
    """First non-empty component of a raw field, unescaped."""
    return next((d.unescape(p).strip() for p in value.split(d.component) if p.strip()), "")


def parse_message(records: list[str]) -> AstmMessage:
    """Interprets E1394 records (H, P, O, R, Q, C, L)."""
    msg = AstmMessage(records=records)
    d = Delimiters()
    sample = None

    for record in records:
        record = record.rstrip("\r\n")
        if not record:
            continue
        kind = record[0].upper()
        if kind == "H":
            d = Delimiters.from_header(record)
            continue
        f = record.split(d.field)
        raw = lambda i: f[i] if i < len(f) else ""  # noqa: E731  (split components before unescaping)
        text = lambda i: d.unescape(raw(i)).strip()  # noqa: E731

        if kind == "O":
            sample = _first(raw(2), d) or _first(raw(3), d) or None
            if sample:
                msg.results.setdefault(sample, [])
        elif kind == "R" and sample:
            status = text(8).upper()
            value = _first(raw(3), d)
            code = _test_code(raw(2), d)
            if code and value and status not in ("X", "I"):  # X = cannot be done, I = in progress
                msg.results[sample].append(AstmResult(code=code, value=value, unit=_first(raw(4), d),
                                                      reference_range=text(5), flag=text(6), status=status))
        elif kind == "Q":
            for repeat in raw(2).split(d.repeat):
                qid = _first(repeat, d)
                if qid and qid.upper() != "ALL":
                    msg.queries.append(qid)

    msg.results = {s: r for s, r in msg.results.items() if r}
    return msg


def _esc(value: str, d: Delimiters = Delimiters()) -> str:
    e = d.escape
    return (str(value or "").replace(e, f"{e}E{e}").replace(d.field, f"{e}F{e}")
            .replace(d.component, f"{e}S{e}").replace(d.repeat, f"{e}R{e}"))


def build_order_records(orders: list[dict], sender: str = "HMIS") -> list[str]:
    """Host -> analyser order message (worklist download or reply to a query)."""
    records = [f"H|\\^&|||{_esc(sender)}|||||||P|1"]
    for n, order in enumerate(orders, start=1):
        p = order.get("patient", {})
        dob = (p.get("date_of_birth") or "").replace("-", "")
        records.append("|".join(["P", str(n), _esc(p.get("number", "")), "", "",
                                 f"{_esc(p.get('last_name', ''))}^{_esc(p.get('first_name', ''))}", "", dob, p.get("sex", "U")]))
        tests = "\\".join(f"^^^{_esc(code)}" for code in order.get("instrument_codes", []))
        priority = {"stat": "S", "urgent": "A"}.get(order.get("priority", ""), "R")
        collected = (order.get("collected_at") or "")[:19].replace("-", "").replace(":", "").replace("T", "")
        o = ["O", "1", _esc(order["sample_id"]), "", tests, priority, "", collected, "", "", "", "N", "", "", "",
             _esc(order.get("specimen_type", "")), "", "", "", "", "", "", "", "", "", "O"]
        records.append("|".join(o))
    records.append("L|1|N")
    return records


def build_no_order_records(sample_id: str, sender: str = "HMIS") -> list[str]:
    """Reply to a query when HMIS has nothing for that sample (status 'I' = no information)."""
    return [f"H|\\^&|||{_esc(sender)}|||||||P|1", f"Q|1|^{_esc(sample_id)}||^^^ALL||||||||X", "L|1|I"]
