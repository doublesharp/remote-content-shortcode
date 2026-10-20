"""HTTP, HTTPS and DNS fixture confined to the internal Docker test network."""

from collections import Counter
import gzip
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import ipaddress
import json
from pathlib import Path
import socketserver
import ssl
import struct
import threading
import time
from urllib.parse import parse_qs, urlsplit

ADDRESS = "11.231.47.10"
counts = Counter()
rebind_private = False
lock = threading.Lock()


class DNS(socketserver.BaseRequestHandler):
    def handle(self):
        packet, channel = self.request
        offset, labels = 12, []
        while packet[offset]:
            length = packet[offset]
            labels.append(packet[offset + 1:offset + 1 + length].decode())
            offset += length + 1
        offset += 1
        kind = struct.unpack("!H", packet[offset:offset + 2])[0]
        name = ".".join(labels).lower()
        question = packet[12:offset + 4]
        address = None
        if kind == 1 and name != "ipv6-only.rcs.test":
            address = "127.0.0.1" if name == "private.rcs.test" or (name == "rebind.rcs.test" and rebind_private) else ADDRESS
        elif kind == 28 and name == "ipv6-only.rcs.test":
            address = "2606:4700:4700::1111"
        answer = b""
        if address:
            packed = ipaddress.ip_address(address).packed
            answer = b"\xc0\x0c" + struct.pack("!HHIH", kind, 1, 0, len(packed)) + packed
        header = packet[:2] + struct.pack("!HHHHH", 0x8180, 1, bool(answer), 0, 0)
        channel.sendto(header + question + answer, self.client_address)


class HTTP(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_GET(self):
        global rebind_private
        path = urlsplit(self.path).path
        with lock:
            counts[self.headers.get("Host", "") + path] += 1
        if path == "/control":
            rebind_private = parse_qs(urlsplit(self.path).query).get("private", ["0"])[0] == "1"
        if path == "/stats":
            with lock:
                body = json.dumps(counts).encode()
        else:
            body = b"<p>fixture success</p>"
        if path in ("/encoded", "/lf-encoded"):
            body = gzip.compress(b"x" * 2000000)
            if path == "/lf-encoded":
                self.connection.sendall(b"HTTP/1.1 200 OK\r\nX-Test: yes\nContent-Encoding: gzip\r\nContent-Length: " + str(len(body)).encode() + b"\r\n\r\n" + body)
                self.close_connection = True
                return
        if path == "/chunked-overflow":
            try:
                self.connection.sendall(b"HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n")
                chunk = b"4000\r\n" + b"x" * 16384 + b"\r\n"
                for _ in range(64):
                    self.connection.sendall(chunk)
                time.sleep(4)
                self.connection.sendall(b"0\r\n\r\n")
            except (BrokenPipeError, ConnectionResetError):
                pass
            self.close_connection = True
            return
        status = 503 if path == "/failure" else 200
        location = {"/redirect": "/ok", "/private-redirect": "http://private.rcs.test/ok", "/loop": "/loop"}.get(path)
        if location:
            status, body = 302, b""
        if path == "/slow-overflow":
            body = b"x" * 1048576
        self.send_response(status)
        if location:
            self.send_header("Location", location)
        if path == "/encoded":
            self.send_header("Content-Encoding", "gzip")
        self.send_header("Content-Length", str(len(body) + (1 if path == "/slow-overflow" else 0)))
        self.end_headers()
        try:
            self.wfile.write(body)
            self.wfile.flush()
            if path == "/slow-overflow":
                time.sleep(4)
                self.wfile.write(b"x")
        except (BrokenPipeError, ConnectionResetError):
            pass


certificates = Path("/certificates")
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(certificates / "server.pem", certificates / "server.key")
untrusted = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
untrusted.load_cert_chain(certificates / "untrusted.pem", certificates / "untrusted.key")


def choose_certificate(connection, name, _):
    if name == "untrusted.rcs.test":
        connection.context = untrusted


context.set_servername_callback(choose_certificate)
dns = socketserver.ThreadingUDPServer(("0.0.0.0", 53), DNS)
http = ThreadingHTTPServer(("0.0.0.0", 80), HTTP)
https = ThreadingHTTPServer(("0.0.0.0", 443), HTTP)
https.socket = context.wrap_socket(https.socket, server_side=True)
for service in (dns, http, https):
    threading.Thread(target=service.serve_forever, daemon=True).start()
threading.Event().wait()
