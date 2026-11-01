<?php
/**
 * Transport test message.
 *
 * Not content-reviewed: this template exists only to prove that the site can
 * send mail through its configured mailer.
 *
 * @package Epic_Mail_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="color-scheme" content="light">
  <title>Mail delivery test · {{site_name}}</title>
  <style>
    @media screen and (max-width:600px) { .email-shell { width:100% !important; } .email-pad { padding-left:22px !important; padding-right:22px !important; } }
  </style>
</head>
<body style="margin:0;padding:0;background:#FAF7F2;color:#1A1512;-webkit-text-size-adjust:100%">
  <div style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;mso-hide:all">Mail transport test from {{site_name}}.</div>
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;background:#FAF7F2">
    <tr><td align="center" style="padding:28px 12px">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" class="email-shell" style="width:100%;max-width:600px;border-collapse:collapse;background:#FAF7F2">
        <tr><td class="email-pad" style="padding:8px 36px 20px;border-bottom:1px solid #E8E1D5">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse">
            <tr>
              <td valign="middle" style="padding:0 12px 0 0"><img src="https://www.epicroastery.coffee/android-chrome-192x192.png" alt="EPIC Coffee Roaster" width="44" height="44" style="display:block;width:44px;height:44px;border:0"></td>
              <td valign="middle">
                <div style="font-family:Georgia,'Times New Roman',serif;font-size:23px;line-height:1.1;letter-spacing:2px;color:#1A1512">EPIC</div>
                <div style="margin-top:5px;font-family:Arial,Helvetica,sans-serif;font-size:10px;line-height:1.4;letter-spacing:1.8px;color:#6F6659">MAIL TRANSPORT TEST</div>
              </td>
            </tr>
          </table>
        </td></tr>
        <tr><td class="email-pad" style="padding:30px 36px 0">
          <div style="margin:0 0 12px;color:#9A330A;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:1.4px;line-height:1.4;text-transform:uppercase">Delivery check</div>
          <h1 style="margin:0 0 22px;color:#1A1512;font-family:Georgia,'Times New Roman',serif;font-size:32px;font-weight:normal;line-height:1.18">This message proves the site can send mail.</h1>
          <p style="margin:0 0 22px;color:#4A423A;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.65">Sent from {{site_name}} through the site's configured mailer. If you can read this, WordPress, the mail transport and the inbox all agree.</p>
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;margin:0 0 22px;border:1px solid #E8E1D5;background:#FFFFFF">
            <tr><td style="padding:14px 16px;border-bottom:1px solid #E8E1D5">
              <div style="margin:0 0 4px;color:#9A330A;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:1.4px;line-height:1.4">SITE</div>
              <div style="color:#4A423A;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55">{{site_name}}</div>
            </td></tr>
            <tr><td style="padding:14px 16px;border-bottom:1px solid #E8E1D5">
              <div style="margin:0 0 4px;color:#9A330A;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:1.4px;line-height:1.4">SENT AT</div>
              <div style="color:#4A423A;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55">{{timestamp}}</div>
            </td></tr>
            <tr><td style="padding:14px 16px">
              <div style="margin:0 0 4px;color:#9A330A;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:1.4px;line-height:1.4">MAILER</div>
              <div style="color:#4A423A;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55">{{mailer}}</div>
            </td></tr>
          </table>
          <p style="margin:0 0 28px;color:#4A423A;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6">Note: {{note}}</p>
        </td></tr>
        <tr><td class="email-pad" style="padding:15px 36px 22px;border-top:1px solid #E8E1D5;color:#6F6659;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6">
          EPIC Coffee Roaster · TP. Hồ Chí Minh<br><a href="https://www.epicroastery.coffee" style="color:#6F6659;text-decoration:underline">epicroastery.coffee</a>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
