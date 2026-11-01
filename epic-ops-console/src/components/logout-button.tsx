"use client";

import { LogOut } from "lucide-react";

export function LogoutButton() {
  const href = window.EPIC_OPS?.logoutUrl ?? "/wp-login.php?action=logout";
  return (
    <a className="logout" href={href} aria-label="Đăng xuất" title="Đăng xuất">
      <LogOut size={17} aria-hidden="true" />
    </a>
  );
}
