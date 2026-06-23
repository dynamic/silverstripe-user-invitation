<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
</head>
<body style="margin:0;padding:0;background-color:#f4f4f4;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f4f4;">
        <tr>
            <td align="center" style="padding:40px 20px;">
                <table border="0" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;background-color:#ffffff;border-radius:8px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">

                    <%-- Header --%>
                    <tr>
                        <td align="center" style="background-color:#1a1a1a;padding:28px 40px;">
                            <p style="margin:0;font-size:20px;font-weight:bold;color:#ffffff;letter-spacing:0.5px;">$SiteName.XML</p>
                        </td>
                    </tr>

                    <%-- Body --%>
                    <tr>
                        <td style="padding:40px 40px 24px;color:#333333;">
                            <h1 style="margin:0 0 20px;font-size:26px;font-weight:bold;color:#1a1a1a;line-height:1.2;">
                                <%t UserInvitation.EMAIL_HEADING "You've been invited!" %>
                            </h1>
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.6;">
                                <%t UserInvitation.EMAIL_GREETING "Hi {name}," name=$InviteeName.XML %>
                            </p>
                            <p style="margin:0 0 28px;font-size:16px;line-height:1.6;">
                                <%t UserInvitation.EMAIL_BODY "{inviter} has invited you to become a member of {site}. Click the button below to set up your account." inviter=$InviterName.XML site=$SiteName.XML %>
                            </p>

                            <%-- CTA button --%>
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center" style="padding-bottom:28px;">
                                        <a href="$AcceptLink" style="display:inline-block;background-color:#0066cc;color:#ffffff;font-size:16px;font-weight:bold;text-decoration:none;padding:14px 36px;border-radius:4px;letter-spacing:0.3px;">
                                            <%t UserInvitation.EMAIL_ACCEPT_BUTTON "Accept Invitation" %>
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <%-- Plain-text fallback URL (#20) --%>
                            <p style="margin:0 0 8px;font-size:14px;color:#666666;">
                                <%t UserInvitation.EMAIL_LINK_HELP "If the button above doesn't work, copy and paste this link into your browser:" %>
                            </p>
                            <p style="margin:0 0 28px;font-size:13px;word-break:break-all;">
                                <a href="$AcceptLink" style="color:#0066cc;text-decoration:underline;">$AcceptLink</a>
                            </p>
                        </td>
                    </tr>

                    <%-- Expiry notice (#15) --%>
                    <tr>
                        <td style="padding:0 40px 36px;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td style="background-color:#fffbeb;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:0 4px 4px 0;">
                                        <p style="margin:0;font-size:14px;color:#92400e;line-height:1.5;">
                                            <%t UserInvitation.EMAIL_EXPIRY "This invitation expires on {date} ({days} days from now)." date=$ExpiryDate days=$ExpiryDays %>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <%-- Footer --%>
                    <tr>
                        <td style="background-color:#f9f9f9;border-top:1px solid #eeeeee;padding:20px 40px;text-align:center;font-size:13px;color:#999999;">
                            <p style="margin:0;">$SiteName.XML</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
