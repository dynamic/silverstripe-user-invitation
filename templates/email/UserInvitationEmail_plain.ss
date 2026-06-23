<%t UserInvitation.EMAIL_GREETING "Hi {name}," name=$InviteeName %>

<%t UserInvitation.EMAIL_BODY "{inviter} has invited you to become a member of {site}. Click the link below to set up your account." inviter=$InviterName site=$SiteName %>

<%t UserInvitation.EMAIL_ACCEPT_BUTTON "Accept Invitation" %>:
$AcceptLink

---
<%t UserInvitation.EMAIL_EXPIRY "This invitation expires on {date} ({days} days from now)." date=$ExpiryDate days=$ExpiryDays %>

$SiteName
