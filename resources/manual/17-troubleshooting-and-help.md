# Troubleshooting and help

This chapter lists the problems people meet most often, with what to do about each. If your problem is not here, the end of the chapter explains how to get help.

## Signing in

**I forgot my password.** On the sign-in page, click **Forgot your password?**, enter your work email and click **Email me a reset link**. Open the email and set a new password. If no email arrives, check your spam folder, then ask your Hospital Admin to reset your password from **User accounts**.

**The two-step code is not accepted.** The code changes every 30 seconds. Type the newest code from your authenticator app. If codes keep failing, check that your phone's date and time are set automatically, because a wrong clock gives wrong codes.

**I lost the phone with my authenticator app.** On the code screen, type one of your recovery codes instead. Each one works once. When you are in, open **Account security** and set up two-step sign-in again on your new phone. If you have no recovery codes, use **No phone and no recovery code? WhatsApp us** on the code screen. See [Getting started](01-getting-started.md) and [Security and privacy](15-security-and-privacy.md).

**I was signed out while working.** True-Doctor signs you out after a period with no activity, to protect patient records on shared computers. Sign in again. On the web, save your work before you step away.

## A menu item is missing

You see only the pages your role allows. A receptionist, for example, does not see the finance pages, and a nurse does not see billing settings. If you need a page for your work, ask your Hospital Admin to check your role under **User accounts**. The roles are listed in [Getting started](01-getting-started.md).

Some menus also depend on your practice type. A dental clinic may have no wards, and a clinic without a laboratory has no lab menu. The kind of practice, set by the Hospital Admin under **Settings**, **Your practice**, decides which parts of the system the menu shows. Changing it never removes anything already recorded. See [Setting up your hospital](02-setting-up.md).

While the hospital's setup is not finished, the menu shows only the **Configuration** group with the setup pages. Finish **Set up your hospital** and the full menu appears.

## Printing receipts

**There is no print button for the receipt printer.** The Hospital Admin must choose the printer size first: **Settings**, **Billing settings**, **Receipt printer**, then **80 mm roll** or **58 mm roll**. With **None (A4 only)**, receipts print on A4 only.

**The receipt is cut off or too small.** Check that the size chosen in **Receipt printer** matches the paper in the printer. In the browser's print window, choose the receipt printer, set margins to none, and turn off headers and footers.

More on receipts is in [Billing and payments](07-billing-and-payments.md).

## The mobile app

**Work is not sending.** Tap the connection badge at the top of the app to open **Sync**. If it says **Offline**, your work is safe on the phone and goes when the connection returns. When you have signal, tap **Sync now**.

**Something says it needs my attention.** On **Sync**, look under **Needs your attention**. Tap **Try again** for items that were not sent, or choose **Keep mine** or **Keep the server's** for a conflict. Do not discard anything unless you are sure. See [The mobile app](14-mobile-app.md).

**The app will not let me record clinical work.** The phone's clock is too far from the server's. Set the phone's date and time to automatic, then try again.

**The app says it needs updating.** Tap **Download the update**. Your unsent work is kept and sent after the update.

**A page says "Not available on your server yet".** Your hospital's server is older than the app. Tap **Open this page on the web**, and ask your administrator to update the server.

**The subscription has ended.** The app shows **Subscription ended**. Nothing recorded is lost. The Hospital Admin can renew under **Settings**, **Subscription** on the web.

## Your own copy

**A warning says the licence has ended or is invalid.** Patient care is not affected; only updates stop. See the warnings table in [Your own copy](16-your-own-copy.md).

**An update stopped.** The previous release and database are put back automatically. Read the log on **Licence & updates** and send it to support.

**Emails and reminders do not go out.** The scheduler is probably not running. Check the **Scheduler** figure on **Licence & updates** and the cron job in cPanel.

## Getting help

### The user manual

Open **Help**, **User manual** at the bottom of the sidebar to read this manual inside True-Doctor. Click **PDF** to download or print it.

### The Help menu

The question mark icon in the top bar opens **Help and support** from every page. It has:

- **Guide for this screen**, a short guide to the page you are on.
- **WhatsApp support**, which opens WhatsApp with a message that already names your role, your hospital and the page you are on. Add what went wrong.
- A phone number to call and **Email support**, when your support provider has them, with their working hours.

If your hospital has its own copy that someone else installed, these options reach your installer or your own IT team instead of True-Doctor.

### From the mobile app

Open **My account** and scroll to **Something not right?**. Tap **Send to support on WhatsApp** to send a report about the app with your message. It never includes patient details. Many app screens also have a WhatsApp help line at the bottom.

### What to include

To get a quick answer, say:

- what you were trying to do, and on which page;
- what you expected, and what happened instead;
- the exact message on the screen, or a screenshot;
- whether it happens on the web, the app, or both.

Never send passwords, two-step codes, recovery codes, backups or patient details to anyone, including support. Support staff never need them.
