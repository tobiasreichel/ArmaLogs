"""First-run configuration dialog for the Windows client."""
import json
import os
import socket
import tkinter as tk
from pathlib import Path
from tkinter import messagebox, ttk

from .config import default_config


DEFAULT_LOG_ROOT = Path.home() / "Documents/My Games/ArmaReforger/logs"
DEFAULT_SERVER_URL = "https://armalogs.reichel.network/upload.php"


def run_setup_dialog() -> bool:
    """Show a simple setup dialog. Returns True if config was saved."""
    cfg = default_config()

    # Use saved values, but prefill defaults if this is the first run.
    saved_url = cfg.get("server_url", "")
    saved_log = cfg.get("log_root", "")

    if not saved_url:
        cfg.set("server_url", DEFAULT_SERVER_URL)
    if not saved_log:
        cfg.set("log_root", str(DEFAULT_LOG_ROOT))

    url_default = cfg.get("server_url", DEFAULT_SERVER_URL)
    log_default = cfg.get("log_root", str(DEFAULT_LOG_ROOT))

    root = tk.Tk()
    root.title("ArmaLogs Client Setup")
    root.geometry("560x420")
    root.resizable(False, False)
    root.configure(bg="#151821")

    # Center window
    root.update_idletasks()
    x = (root.winfo_screenwidth() // 2) - (560 // 2)
    y = (root.winfo_screenheight() // 2) - (420 // 2)
    root.geometry(f"+{x}+{y}")

    style = ttk.Style(root)
    style.theme_use("clam")
    style.configure("TFrame", background="#151821")
    style.configure("TLabel", background="#151821", foreground="#d8dce4", font=("Segoe UI", 10))
    style.configure("TEntry", fieldbackground="#0f1115", foreground="#d8dce4", insertcolor="#d8dce4")
    style.configure("TButton", background="#4f8cff", foreground="#ffffff", font=("Segoe UI", 10))
    style.map("TButton", background=[("active", "#6fa3ff")])

    frame = ttk.Frame(root, padding=20)
    frame.pack(fill=tk.BOTH, expand=True)

    ttk.Label(frame, text="Server URL").grid(row=0, column=0, sticky=tk.W, pady=(0, 4))
    url_var = tk.StringVar(value=url_default)
    url_entry = ttk.Entry(frame, textvariable=url_var, width=60)
    url_entry.grid(row=1, column=0, columnspan=2, sticky=tk.EW, pady=(0, 12))

    ttk.Label(frame, text="Your Name").grid(row=2, column=0, sticky=tk.W, pady=(0, 4))
    name_var = tk.StringVar(value=cfg.get("name", ""))
    name_entry = ttk.Entry(frame, textvariable=name_var, width=40)
    name_entry.grid(row=3, column=0, sticky=tk.W, pady=(0, 12))

    ttk.Label(frame, text="Arma Reforger Logs Folder").grid(row=4, column=0, sticky=tk.W, pady=(0, 4))
    log_var = tk.StringVar(value=log_default)
    log_entry = ttk.Entry(frame, textvariable=log_var, width=60)
    log_entry.grid(row=5, column=0, columnspan=2, sticky=tk.EW, pady=(0, 12))

    ttk.Label(frame, text="Scan Interval (seconds)").grid(row=6, column=0, sticky=tk.W, pady=(0, 4))
    interval_var = tk.StringVar(value=str(cfg.get("scan_interval_seconds", 30)))
    interval_entry = ttk.Entry(frame, textvariable=interval_var, width=10)
    interval_entry.grid(row=7, column=0, sticky=tk.W, pady=(0, 12))

    ttk.Label(frame, text="Friend Token").grid(row=8, column=0, sticky=tk.W, pady=(0, 4))
    token_var = tk.StringVar(value=cfg.get("token", ""))
    token_entry = ttk.Entry(frame, textvariable=token_var, width=60, show="•")
    token_entry.grid(row=9, column=0, columnspan=2, sticky=tk.EW, pady=(0, 6))

    btn_frame = ttk.Frame(frame)
    btn_frame.grid(row=10, column=0, columnspan=2, sticky=tk.W, pady=(0, 10))
    ttk.Button(btn_frame, text="Request token", command=lambda: request_token(url_var, name_var, token_var, error_var)).pack(side=tk.LEFT)

    error_var = tk.StringVar()
    error_label = ttk.Label(frame, textvariable=error_var, foreground="#ff4f4f", wraplength=520)
    error_label.grid(row=11, column=0, columnspan=2, sticky=tk.EW, pady=(0, 10))

    def request_token(url_var, name_var, token_var, error_var):
        server_url = normalize_url(url_var.get().strip())
        name = name_var.get().strip()
        if not server_url:
            error_var.set("Server URL is required.")
            return
        if not name:
            error_var.set("Your name is required to request a token.")
            return
        try:
            import requests
            resp = requests.post(
                server_url.replace("/upload.php", "/request-token.php"),
                json={"name": name, "hostname": socket.gethostname()},
                timeout=30,
            )
            data = resp.json()
            if data.get("ok") and data.get("status") == "pending":
                token_var.set(data.get("token", ""))
                error_var.set("Request submitted. Wait for admin approval, then click Save & Start.")
            else:
                error_var.set(data.get("error", "Unknown response from server"))
        except Exception as exc:
            error_var.set(f"Token request failed: {exc}")

    def normalize_url(url: str) -> str:
        if not url.startswith(("http://", "https://")):
            url = "https://" + url
        if not url.endswith("/upload.php"):
            url = url.rstrip("/") + "/upload.php"
        return url

    def save():
        server_url = normalize_url(url_var.get().strip())
        token = token_var.get().strip()
        name = name_var.get().strip()
        log_root = log_var.get().strip()
        interval = interval_var.get().strip()

        if not server_url or not token or not log_root:
            error_var.set("Server URL, token, and log folder are required.")
            return

        try:
            interval_sec = max(10, int(interval))
        except ValueError:
            error_var.set("Scan interval must be a number of seconds (min 10).")
            return

        log_path = Path(log_root)
        if not log_path.is_dir():
            if not messagebox.askyesno(
                "Folder not found",
                f"The folder\n{log_path}\ndoes not exist yet.\n\nSave anyway and create it?",
            ):
                return
            log_path.mkdir(parents=True, exist_ok=True)

        cfg.set("server_url", server_url)
        cfg.set("token", token)
        cfg.set("name", name)
        cfg.set("log_root", str(log_path))
        cfg.set("scan_interval_seconds", interval_sec)

        root.destroy()

    def cancel():
        root.destroy()
        os._exit(0)

    action_frame = ttk.Frame(frame)
    action_frame.grid(row=12, column=0, columnspan=2, sticky=tk.E)
    ttk.Button(action_frame, text="Save & Start", command=save).pack(side=tk.RIGHT, padx=(8, 0))
    ttk.Button(action_frame, text="Cancel", command=cancel).pack(side=tk.RIGHT)

    frame.columnconfigure(0, weight=1)

    root.protocol("WM_DELETE_WINDOW", cancel)
    root.mainloop()

    return cfg.is_complete()
