"""Byte-stream connections to analysers: TCP server, TCP client or serial port."""

from __future__ import annotations

import logging
import socket
import time

log = logging.getLogger("hmis.link")


class LinkClosed(Exception):
    pass


class Link:
    """A connected byte stream. read() returns b'' on timeout."""

    def read(self, size: int = 4096, timeout: float = 1.0) -> bytes:
        raise NotImplementedError

    def write(self, data: bytes) -> None:
        raise NotImplementedError

    def close(self) -> None:
        pass


class SocketLink(Link):
    def __init__(self, sock: socket.socket, peer: str):
        self.sock = sock
        self.peer = peer

    def read(self, size: int = 4096, timeout: float = 1.0) -> bytes:
        self.sock.settimeout(timeout)
        try:
            data = self.sock.recv(size)
        except socket.timeout:
            return b""
        except OSError as e:
            raise LinkClosed(str(e)) from None
        if data == b"":
            raise LinkClosed("connection closed by analyser")
        return data

    def write(self, data: bytes) -> None:
        try:
            self.sock.sendall(data)
        except OSError as e:
            raise LinkClosed(str(e)) from None

    def close(self) -> None:
        try:
            self.sock.close()
        except OSError:
            pass


class SerialLink(Link):
    def __init__(self, port: str, baudrate: int = 9600, bytesize: int = 8, parity: str = "N", stopbits: float = 1):
        try:
            import serial  # pyserial
        except ImportError as e:  # pragma: no cover - depends on installation
            raise SystemExit("Serial analysers need pyserial: pip install pyserial") from e
        self.serial = serial.Serial(port=port, baudrate=baudrate, bytesize=bytesize, parity=parity, stopbits=stopbits, timeout=1)
        self.peer = port

    def read(self, size: int = 4096, timeout: float = 1.0) -> bytes:
        self.serial.timeout = timeout
        first = self.serial.read(1)
        if not first:
            return b""
        return first + self.serial.read(self.serial.in_waiting or 0)

    def write(self, data: bytes) -> None:
        self.serial.write(data)
        self.serial.flush()

    def close(self) -> None:
        self.serial.close()


def connections(config: dict, stop) -> "iter[Link]":
    """Yields connected links for an instrument until stop is set.

    transport "tcp-server": the gateway listens and the analyser connects (most common).
    transport "tcp-client": the gateway connects to the analyser's IP/port.
    transport "serial":     RS-232 cable (COM3 on Windows, /dev/ttyUSB0 on Linux).
    """
    transport = config.get("transport", "tcp-server")

    if transport == "serial":
        while not stop.is_set():
            try:
                yield SerialLink(config["port"], int(config.get("baudrate", 9600)), int(config.get("bytesize", 8)),
                                 config.get("parity", "N"), float(config.get("stopbits", 1)))
            except Exception as e:  # noqa: BLE001
                log.error("%s: cannot open %s (%s); retrying in 10 s", config["code"], config.get("port"), e)
                stop.wait(10)
        return

    if transport == "tcp-client":
        while not stop.is_set():
            try:
                sock = socket.create_connection((config["host"], int(config["port"])), timeout=10)
                yield SocketLink(sock, f"{config['host']}:{config['port']}")
            except OSError as e:
                log.error("%s: cannot connect to %s:%s (%s); retrying in 10 s", config["code"], config.get("host"), config.get("port"), e)
                stop.wait(10)
        return

    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind((config.get("listen", "0.0.0.0"), int(config["port"])))
    server.listen(1)
    server.settimeout(1.0)
    log.info("%s: listening on port %s", config["code"], config["port"])
    try:
        while not stop.is_set():
            try:
                sock, addr = server.accept()
            except socket.timeout:
                continue
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
            yield SocketLink(sock, f"{addr[0]}:{addr[1]}")
    finally:
        server.close()


def sleep_until(stop, seconds: float) -> None:
    deadline = time.time() + seconds
    while not stop.is_set() and time.time() < deadline:
        stop.wait(min(1.0, deadline - time.time()))
