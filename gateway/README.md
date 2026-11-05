# HMIS device gateway

A small program that runs on a computer in the laboratory. It connects your analysers to HMIS:

```
 Analyser ──(cable or LAN: ASTM / HL7)──► Gateway PC ──(HTTPS)──► HMIS
          ◄── worklist / barcode query ──            ◄── orders ──
```

- **Results in:** the analyser sends results, the gateway forwards them to HMIS, and they appear in the lab worklist as *awaiting verification*.
- **Orders out** (two-way analysers only): the analyser receives the worklist, or asks HMIS what to run when it scans a tube's barcode.
- **No lost results:** results are stored in a local spool first. If the internet is down, they are sent automatically when it returns.

Supports **ASTM E1381/E1394 (LIS2-A2)** and **HL7 v2.x over MLLP**, via a **network** connection (the analyser connects to the gateway, or the other way round) or an **RS-232 serial** cable.

## Requirements

- Any always-on PC or mini-PC on the lab network (Windows 10/11 or Linux).
- **Python 3.10+** from python.org. On Windows, tick "Add python.exe to PATH" during installation.
- For serial analysers only: `pip install pyserial` (and a USB-to-serial adapter if the PC has no COM port).

## Set up in 6 steps

1. **In HMIS**, open **Diagnostics → Lab analysers** and add a **gateway**. Copy the token: it is shown only once.
2. **Add each analyser** there with a **code** (e.g. `BC30-LAB1`) and its protocol. Then map its test codes. **Presets** fill in the usual haematology and chemistry codes; check them against the analyser's LIS/host-interface manual.
3. **Copy this `gateway` folder** to the lab PC. Copy `config.example.json` to `config.json` and fill in:
   - `token`: from step 1.
   - one entry per analyser with the **same `code`** as in HMIS, the `protocol`, and the connection:

   | Analyser LIS setting | Gateway `transport` | Also set |
   |---|---|---|
   | Analyser connects to a host IP and port | `tcp-server` | `port` (e.g. 5100); point the analyser at the gateway PC's IP and this port |
   | Analyser listens on its own IP and port | `tcp-client` | `host`, `port` |
   | RS-232 serial cable | `serial` | `port` (`COM3` or `/dev/ttyUSB0`), `baudrate`, `bytesize`, `parity`, `stopbits`: copy them from the analyser's settings |

4. **Test the connection:** `python -m hmis_gateway --config config.json --check`. Every analyser should show `OK`.
5. **Run it:** `python -m hmis_gateway --config config.json`. Run one sample on the analyser and check that it appears in HMIS under **Laboratory → Awaiting verification**, marked with the analyser's name.
6. **Make it permanent:**
   - **Linux:** copy `hmis-gateway.service` to `/etc/systemd/system/`, then `systemctl enable --now hmis-gateway`.
   - **Windows:** install it as a service with [NSSM](https://nssm.cc): `nssm install HMISGateway "C:\Python312\python.exe" "-m hmis_gateway --config C:\hmis-gateway\config.json"` with the startup directory `C:\hmis-gateway`. Or use Task Scheduler "At startup".

## Practise without an analyser

`simulator.py` behaves like an analyser:

```
python simulator.py --protocol hl7  --port 5100 --sample SPM-26-000123 --panel fbc
python simulator.py --protocol astm --port 5200 --sample SPM-26-000124 --panel glucose
python simulator.py --protocol astm --port 5200 --query SPM-26-000125
```

Use a specimen number from a sample marked **collected** in HMIS. The result then appears in the worklist.

## Troubleshooting

| Symptom | Check |
|---|---|
| `--check` says `FAILED … 401` | Token wrong or rotated: paste the current one from HMIS |
| `MISSING <code>` | The analyser code in `config.json` differs from HMIS, or it is assigned to another gateway |
| Analyser shows "LIS/host communication error" | Port, IP and transport match the analyser's settings? For serial: baud rate, parity, cable (null-modem?) |
| HMIS message log: **unmatched**, "No sample with this ID" | The tube's barcode is not an HMIS specimen number, or the sample was not marked *collected* |
| **unmatched**, "None of the analyser codes are mapped" | Add the codes shown in the message to the analyser's mapping in HMIS |
| **partial** | Some codes are unmapped: they were ignored; map them if you need them |
| Results stuck locally | The log shows "HMIS unreachable"; they are sent automatically when the internet returns |

Logs are written to `hmis_gateway.log`, and every message is also listed in HMIS under **Lab analysers → Recent messages**.

## Development

`python -m unittest discover -s tests` runs the protocol and end-to-end tests. Only the standard library is needed.
