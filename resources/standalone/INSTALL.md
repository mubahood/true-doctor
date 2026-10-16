# Install True-Doctor on your own server

This guide takes you from the downloaded file to a working True-Doctor on your
own hosting. It takes about 20 minutes. You need no programmer, no terminal and
no command line — only your hosting control panel (cPanel) and a web browser.

The same guide is in the download page of your buyer portal and, as
`INSTALL.md`, inside the ZIP.

{{support}}

## What you need

- **Hosting with cPanel** (or similar), **PHP 8.2 or newer**, and **MySQL 5.7+
  or MariaDB 10.3+**. Almost every shared host today qualifies.
- **A domain or subdomain with HTTPS** — for example `hms.yourhospital.com` —
  pointing at that hosting. In cPanel, make sure its SSL certificate is active
  (cPanel → SSL/TLS Status).
- From your buyer portal: the **ZIP file** and your **`licence.json`**.

Your licence is for one address. If you will install on a different address
from the one you gave at purchase, change it first in the buyer portal
(**Licence → Change address**) and download `licence.json` again.

## 1. Find your domain's document root

In cPanel, open **Domains**. Next to your domain you will see its *document
root*, for example `/home/youruser/public_html` or
`/home/youruser/hms.yourhospital.com`. Note it down.

Use a domain or subdomain that has nothing else on it. If its document root
already holds another website, use a new subdomain instead.

## 2. Upload and extract the ZIP

Open **File Manager** and go to your **home folder** — the folder that
*contains* `public_html` (one level above it). Upload the ZIP there, then
right-click it and choose **Extract**. You now have three things side by side:

```
true_doctor_app/      the application — stays here, never public
web_root_files/       the public files
PACKAGE.json          the signed list of every file
```

## 3. Move the public files

Open `web_root_files`, select **everything inside it** (choose *Select All* and
make sure hidden files such as `.htaccess` are shown — File Manager → Settings →
*Show Hidden Files*), and **Move** it into your domain's document root from
step 1.

Leave `true_doctor_app` and `PACKAGE.json` where they are, in your home folder.
The application must never be inside the document root.

## 4. Create an empty database

In cPanel, open **MySQL® Databases**:

1. Create a new database, for example `youruser_hms`.
2. Create a new database user with a strong password.
3. Add the user to the database and tick **ALL PRIVILEGES**.

Write down the full database name, user name and password — cPanel adds your
account name in front of both names.

## 5. Run the installer

Open `https://your-address/install.php` in your browser.

1. The **server checks** must all be green. If one is red, the line says what
   your host needs to enable (for example a PHP extension in *Select PHP
   Version*).
2. Upload your **`licence.json`** and check the address.
3. Enter the **database** details from step 4. Host is usually `localhost`.
4. Enter your **organisation's name**, its currency and time zone, and the
   **owner's** name, email and a password of at least 12 characters.
5. **Email** settings can be added now or later. Without them, invitations and
   password resets cannot be sent. Your host's email settings (cPanel → Email
   Accounts → *Connect Devices*) give the SMTP server and port: use the
   **Secure SSL/TLS** outgoing server it shows — often the server's own name,
   such as `server123.yourhost.com`, rather than `mail.yourdomain` — and port 465.

Press **Install True-Doctor** and keep the tab open. The database is prepared in
short steps and the page moves on by itself.

Before writing anything, the installer checks every file against the signed
`PACKAGE.json`. If it reports a file that failed verification, extract the ZIP
again — something changed it on the way.

## 6. Add the scheduler (one cron job)

The finished page shows one command. In cPanel, open **Cron Jobs**, choose
**Once Per Minute**, paste the command and save. It keeps reminders, emails,
overnight ward billing, nightly backups and update checks running.

Administrators see a warning in the system until the scheduler has run.

## 7. Sign in and set up

Go to `https://your-address/admin/login` and sign in as the owner. The setup
wizard opens: choose your **kind of practice** first — it fills in departments,
your price list, rooms, lab tests, stock and your chart of accounts — then add
your team. Everything the wizard suggests can be edited.

## The mobile app and offline devices

Staff use the True-Doctor app from the app store. On its sign-in screen, tap
**Server** and enter your address (`https://your-address`). Everything else
works as it does on True-Doctor online, including offline working.

## Updates

Your licence includes every new release made until the date shown in your
buyer portal. In True-Doctor, go to **Settings → Licence & updates**:

- **Check now** looks for a new release; **Back up and update** installs it.
- No internet on the server? Download the update ZIP from the buyer portal,
  and **upload** it on the same page — or, if it is too large for your host's
  upload limit, put it in `true_doctor_app/storage/app/private/updates/` with
  File Manager and press **Verify and update**.

Every update is checked against its signature first, takes a backup, and puts
everything back automatically if anything goes wrong. The site shows a
maintenance page for the few minutes it takes.

## Backups

True-Doctor backs itself up every night: the database, your settings and every
uploaded document, in one file. The newest seven are kept, in
**Settings → Licence & updates → Backups**.

They are stored on the same server. **Download one regularly and keep it
somewhere else** — a server that fails takes its own backups with it. Inside
each backup, `RESTORE.txt` explains how to restore it.

Keep the settings file `true_doctor_app/.env` safe as well: the `APP_KEY` in it
is needed to read encrypted data, and is inside every backup.

## If something goes wrong

- **The installer stopped part way.** Do not point it at the same half-filled
  database again. Create a new empty database (step 4) and start the installer
  with that one.
- **"The licence does not cover this address."** Change the address in the
  buyer portal and download `licence.json` again.
- **A blank page or "500 error".** The reason is in
  `true_doctor_app/storage/logs/laravel.log`. Send us that file's last lines —
  never send `.env` or a backup to anyone.
- **Stuck?** {{support}}
- **Lost the download email?** On the buyer portal, enter your email for a new
  private link.

A licence problem can stop updates; it never stops your staff seeing patients.
Do not change `APP_EDITION` in `.env` after installation.
