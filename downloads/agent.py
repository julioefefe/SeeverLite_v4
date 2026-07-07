#!/usr/bin/env python3
"""
SeederLinux Lite - Provisioning Agent
=====================================

A lightweight agent that checks in with the SeederLinux Lite server,
downloads provisioning bundles when available, and executes them.

No external dependencies - uses only Python 3 standard library.

Usage:
    python3 seeder-agent.py [--server URL] [--dry-run] [--verbose]

Configuration:
    /etc/seeder/agent.conf  - Server URL and settings
    /etc/seeder/station_token - Station token (auto-generated on first run)

Logs:
    /var/log/seeder/agent.log

Cron (recommended): every 15 minutes
    */15 * * * * /usr/local/bin/seeder-agent >> /var/log/seeder/agent.log 2>&1

Author: SeederLinux Team
License: MIT
"""

import json
import os
import sys
import platform
import subprocess
import socket
import uuid
import configparser
from datetime import datetime
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError

# --- Configuration ---
CONFIG_DIR = "/etc/seeder"
CONFIG_FILE = os.path.join(CONFIG_DIR, "agent.conf")
TOKEN_FILE = os.path.join(CONFIG_DIR, "station_token")
LOG_FILE = "/var/log/seeder/agent.log"
BUNDLE_CACHE_DIR = "/var/cache/seeder"
BUNDLE_FILE = os.path.join(BUNDLE_CACHE_DIR, "bundle.sh")

DEFAULT_SERVER = "https://seederlinux.comara.intraer"
CHECKIN_TIMEOUT = 30  # seconds
DOWNLOAD_TIMEOUT = 60  # seconds


def log(message, level="INFO"):
    """Write a log message to the log file and stdout."""
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{timestamp}] [{level}] {message}"
    print(line, flush=True)
    try:
        os.makedirs(os.path.dirname(LOG_FILE), exist_ok=True)
        with open(LOG_FILE, "a") as f:
            f.write(line + "\n")
    except (IOError, PermissionError):
        pass  # Can't write log, continue anyway


def load_config():
    """Load configuration from config file or defaults."""
    config = configparser.ConfigParser()
    config.read(CONFIG_FILE)

    server = config.get("server", "url", fallback=DEFAULT_SERVER)
    return {"server": server.rstrip("/")}


def get_or_create_token():
    """Get the station token from file, or generate a new one."""
    # Try to read existing token
    if os.path.exists(TOKEN_FILE):
        try:
            with open(TOKEN_FILE, "r") as f:
                token = f.read().strip()
                if token:
                    return token
        except (IOError, PermissionError):
            pass

    # Generate a new token (UUID4)
    token = str(uuid.uuid4())

    # Save it
    try:
        os.makedirs(CONFIG_DIR, exist_ok=True)
        with open(TOKEN_FILE, "w") as f:
            f.write(token)
        os.chmod(TOKEN_FILE, 0o600)
        log(f"Generated new station token: {token[:8]}...", "INFO")
    except (IOError, PermissionError) as e:
        log(f"Cannot save token file: {e}", "ERROR")
        # Continue with in-memory token

    return token


def collect_system_info():
    """Collect system information for check-in."""
    hostname = socket.gethostname()

    # Get IP address
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        ip_address = s.getsockname()[0]
        s.close()
    except Exception:
        ip_address = "127.0.0.1"

    # Get MAC address
    mac_address = ""
    try:
        mac = uuid.getnode()
        mac_address = ":".join(f"{(mac >> ele) & 0xff:02x}" for ele in range(40, -1, -8))
    except Exception:
        mac_address = "00:00:00:00:00:00"

    # Get OS info
    os_name = "Linux"
    os_version = ""

    try:
        # Try /etc/os-release first
        if os.path.exists("/etc/os-release"):
            with open("/etc/os-release", "r") as f:
                for line in f:
                    line = line.strip()
                    if line.startswith("NAME="):
                        os_name = line.split("=", 1)[1].strip('"')
                    elif line.startswith("VERSION="):
                        os_version = line.split("=", 1)[1].strip('"')
        else:
            os_version = platform.release()
    except Exception:
        os_version = platform.release()

    # Get serial number (DMI)
    serial_number = ""
    try:
        result = subprocess.run(
            ["dmidecode", "-s", "system-serial-number"],
            capture_output=True, text=True, timeout=5
        )
        serial_number = result.stdout.strip()
    except Exception:
        pass

    return {
        "hostname": hostname,
        "os_name": os_name,
        "os_version": os_version,
        "ip_address": ip_address,
        "mac_address": mac_address,
        "serial_number": serial_number,
    }


def checkin(server, token, system_info):
    """Send check-in request to the server."""
    url = f"{server}/api/?action=checkin"
    payload = {
        "hostname": system_info["hostname"],
        "os_name": system_info["os_name"],
        "os_version": system_info["os_version"],
        "ip_address": system_info["ip_address"],
        "mac_address": system_info["mac_address"],
        "serial_number": system_info["serial_number"],
    }

    data = json.dumps(payload).encode("utf-8")
    headers = {
        "Content-Type": "application/json",
        "Authorization": f"Bearer {token}",
    }

    req = Request(url, data=data, headers=headers, method="POST")

    try:
        with urlopen(req, timeout=CHECKIN_TIMEOUT) as response:
            body = response.read().decode("utf-8")
            return json.loads(body)
    except HTTPError as e:
        log(f"Check-in HTTP error: {e.code} {e.reason}", "ERROR")
        try:
            error_body = e.read().decode("utf-8")
            log(f"Error response: {error_body}", "ERROR")
        except Exception:
            pass
        return None
    except URLError as e:
        log(f"Check-in network error: {e.reason}", "WARNING")
        return None
    except Exception as e:
        log(f"Check-in error: {e}", "ERROR")
        return None


def download_bundle(server, token, bundle_id):
    """Download a bundle from the server."""
    url = f"{server}/api/?action=bundle-by-id&id={bundle_id}"
    headers = {"Authorization": f"Bearer {token}"}
    req = Request(url, headers=headers, method="GET")

    try:
        with urlopen(req, timeout=DOWNLOAD_TIMEOUT) as response:
            content = response.read()
            return content
    except HTTPError as e:
        log(f"Download HTTP error: {e.code} {e.reason}", "ERROR")
        return None
    except URLError as e:
        log(f"Download network error: {e.reason}", "ERROR")
        return None
    except Exception as e:
        log(f"Download error: {e}", "ERROR")
        return None


def execute_bundle(bundle_path):
    """Execute the downloaded bundle with bash."""
    try:
        os.chmod(bundle_path, 0o755)
        log(f"Executing bundle: {bundle_path}", "INFO")
        result = subprocess.run(
            ["bash", bundle_path],
            capture_output=True,
            text=True,
            timeout=1800,  # 30 minutes max
        )
        if result.returncode == 0:
            log("Bundle executed successfully", "INFO")
            if result.stdout:
                log(f"Bundle stdout: {result.stdout[:500]}", "INFO")
        else:
            log(f"Bundle execution failed (exit code {result.returncode})", "ERROR")
            if result.stderr:
                log(f"Bundle stderr: {result.stderr[:500]}", "ERROR")
        return result.returncode == 0
    except subprocess.TimeoutExpired:
        log("Bundle execution timed out (30 min)", "ERROR")
        return False
    except Exception as e:
        log(f"Bundle execution error: {e}", "ERROR")
        return False


def main():
    dry_run = "--dry-run" in sys.argv
    verbose = "--verbose" in sys.argv or "-v" in sys.argv

    log("=" * 60, "INFO")
    log("SeederLinux Lite Agent starting", "INFO")

    # Load config
    config = load_config()
    server = config["server"]
    log(f"Server: {server}", "INFO")

    # Get or create token
    token = get_or_create_token()
    if verbose:
        log(f"Token: {token[:8]}...", "DEBUG")

    # Collect system info
    system_info = collect_system_info()
    log(f"Hostname: {system_info['hostname']}", "INFO")
    log(f"IP: {system_info['ip_address']}, OS: {system_info['os_name']} {system_info['os_version']}", "INFO")

    if dry_run:
        log("Dry run mode - skipping check-in and bundle execution", "INFO")
        log(f"Would send: {json.dumps(system_info, indent=2)}", "DEBUG")
        return 0

    # Check-in
    log("Sending check-in...", "INFO")
    response = checkin(server, token, system_info)

    if response is None:
        log("Check-in failed - network may be unavailable. Exiting.", "WARNING")
        return 0  # Don't return error - this is expected when offline

    if not response.get("success"):
        log(f"Check-in failed: {response.get('message', 'Unknown error')}", "ERROR")
        return 1

    log(f"Check-in successful. Station ID: {response.get('data', {}).get('station_id', 'N/A')}", "INFO")

    # Check if update is available
    data = response.get("data", {})
    update_available = data.get("update_available", False)
    bundle_id = data.get("latest_bundle_id")

    if not update_available:
        log("No updates available. System is up to date.", "INFO")
        return 0

    if not bundle_id:
        log("Update available but no bundle ID returned. Skipping.", "WARNING")
        return 0

    log(f"Update available! Downloading bundle ID: {bundle_id}", "INFO")

    # Download bundle
    bundle_content = download_bundle(server, token, bundle_id)
    if bundle_content is None:
        log("Failed to download bundle", "ERROR")
        return 1

    # Save bundle
    try:
        os.makedirs(BUNDLE_CACHE_DIR, exist_ok=True)
        with open(BUNDLE_FILE, "wb") as f:
            f.write(bundle_content)
        log(f"Bundle saved to {BUNDLE_FILE} ({len(bundle_content)} bytes)", "INFO")
    except (IOError, PermissionError) as e:
        log(f"Failed to save bundle: {e}", "ERROR")
        return 1

    # Execute bundle
    success = execute_bundle(BUNDLE_FILE)

    if success:
        log("Provisioning completed successfully", "INFO")
        return 0
    else:
        log("Provisioning completed with errors", "ERROR")
        return 1


if __name__ == "__main__":
    try:
        exit_code = main()
    except KeyboardInterrupt:
        log("Agent interrupted by user", "WARNING")
        exit_code = 0
    except Exception as e:
        log(f"Unexpected error: {e}", "ERROR")
        exit_code = 1

    sys.exit(exit_code)
