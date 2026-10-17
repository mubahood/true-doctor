# Your own copy

True-Doctor comes in two forms. Most hospitals use the online service: True-Doctor hosts the system, and the hospital pays a subscription. Some hospitals prefer to buy their own copy once and run it on their own hosting. This chapter is for them.

Your own copy does everything the online service does: patients, visits, lab, imaging, pharmacy, wards, billing, the books, offline devices and the mobile app. The differences are:

| | Online service | Your own copy |
|---|---|---|
| Who hosts it | True-Doctor | You, on your own hosting (cPanel hosting is enough) |
| How you pay | A monthly or yearly subscription | Once, for the copy and a period of updates |
| Organisations | Your hospital among many, each kept apart | One: yours |
| Look | True-Doctor branding | Your logo, colour and tagline on the sign-in page, emails and printed documents |
| Updates | Automatic | You click **Back up and update** while your update period lasts |
| Where the data lives | True-Doctor's server | Your server only |

Everything else in this manual applies to your own copy. Instead of **Subscription**, the Settings menu has **Licence & updates**, described below.

## Buying a copy

1. On the True-Doctor website, click **Self-hosted** in the top menu.
2. Choose a plan. Each plan shows its price, how many months of new releases it includes, and how many staff accounts it allows.
3. Fill in **Your organisation's name**, **Email for your downloads**, and **Address you will install on**, for example a subdomain of your hospital's website. You can change the address later.
4. Click **Pay securely** and pay with mobile money or card.

Your licence is issued as soon as the payment is confirmed, and you are emailed a link to your downloads page. If you pay another way, such as a bank transfer, the True-Doctor team issues the licence for you and sends the same link.

## Your downloads page

The downloads page holds everything you need to install, in one place. To open it:

1. On the True-Doctor website, click **Already bought it? Open your downloads**.
2. Enter the email address you bought with and click **Email me a link**.
3. Open the link in the email. It works for 24 hours. If it expires, ask for a new one the same way.

The page has:

- **Step 1, Download:** **Download installation file** (the release ZIP) and **Download licence.json** (your licence file). **What's new** lists the changes in the release, and **Earlier releases** lets you download older ones.
- **Your licence:** who it is licensed to, the **Address** it may run on, and **Updates until**, the last date of your update period.
- **Change address:** if you will install on a different address, enter the **New address**, click **Change address**, then download licence.json again. You can change the address once every 30 days.
- **Renew updates:** adds more months of new releases, when your plan allows renewal.
- **Payments and receipts:** each payment with its reference and a **Receipt (PDF)** link.
- **User manual:** this manual as a PDF, or to read online.
- **Step 2, Install:** the full installation guide. Click **INSTALL.md** to download it. The same guide is inside the ZIP.

## Installing on cPanel hosting

The installation guide on your downloads page has every step in detail. It takes about 20 minutes and needs no programmer, only your hosting control panel and a web browser. In outline:

1. **Check your hosting.** You need PHP 8.2 or newer, MySQL or MariaDB, and an address with a working security certificate (HTTPS). Use an address that has nothing else on it, such as a new subdomain.
2. **Upload and extract the ZIP** in your hosting home folder, the folder one level above the public web folder.
3. **Move the public files** from the extracted web files folder into your address's web folder. The application folder stays in the home folder, out of public reach.
4. **Create an empty database** and a database user with all privileges, in cPanel's database page.
5. **Run the installer** by opening your address in a browser. It checks your server, then asks for your licence file, the database details, your organisation's name, currency and time zone, the owner's login, and email settings. Click **Install True-Doctor** and keep the tab open until it finishes.
6. **Add the scheduler.** The finished page shows one command. In cPanel's **Cron Jobs**, add it to run once per minute. It keeps reminders, emails, overnight ward billing, nightly backups and update checks running.
7. **Sign in as the owner.** The **Set up your hospital** wizard opens. Choose your kind of practice first, then add your team. See [Setting up your hospital](02-setting-up.md).

Before it writes anything, the installer checks every file against a signed list. If it reports a file that failed the check, extract the ZIP again.

Set your logo, brand colour and tagline under **Settings**, **Hospital letterhead**.

### Connecting the mobile app

Staff install the app as described in [The mobile app](14-mobile-app.md). On the first screen they tap **My hospital runs its own server**, or **Use another server** on the sign-in screen, and type your address. Everything else, including offline working, is the same as on the online service.

## The Licence & updates page

Open **Settings**, **Licence & updates**. Only the Hospital Admin sees it. It shows:

- **Release:** the version you are running, and when it was installed and last updated.
- **Licence:** **Valid**, or **Missing or invalid**.
- **Updates included until:** the last date covered by your licence.
- **Scheduler:** when the scheduler last ran. If it shows **Not running**, add the cron job (step 6 above).

Further down, **This server** shows who the copy is licensed to, emails and jobs waiting to go out, free disk space and the scheduler command.

Updates and backups run in the background, so you can leave the page while they work. A box at the top shows progress: **Waiting to start**, **Updating** or **Backing up**, then **Finished** or **Stopped**, with a log of each step.

## Updating

Your licence includes every new release made until the date in **Updates included until**. True-Doctor checks for updates daily.

1. Open **Licence & updates**.
2. Click **Check now** to look for a new release straight away.
3. If one is available, the page shows its version, date and what is new. Read it.
4. Click **Back up and update** and confirm.

The site shows a maintenance page for a few minutes. True-Doctor first takes a backup, then checks the new release's signature, installs it and checks that everything works. If anything fails, it puts the previous release and database back by itself and the site keeps running on them. The log shows where it stopped.

Choose a quiet time for updates, such as the end of the day.

### Updating a server with no internet

1. On any computer with internet, open your downloads page and download the new installation file.
2. On **Licence & updates**, under **No internet on this server?**, choose the file and click **Upload and update**.

If the file is larger than your hosting allows for uploads, the page tells you the limit and which folder to put the file in with cPanel's File Manager. The file then appears on the page with a **Verify and update** button.

## Backups

True-Doctor backs itself up every night. Each backup is one file holding the database, your settings and every uploaded document and photo. The newest 7 are kept, listed under **Backups** with their date and size.

- To take a backup now, for example before a big change, click **Back up now**.
- To keep a copy, click **Download** next to a backup.

The backups are stored on the same server. If the server fails, its backups go with it. **Download one regularly, at least weekly, and keep it somewhere else**, such as an encrypted drive kept off site. Treat it with care: it contains every patient's details and your system's settings. Never send a backup to anyone, including support.

The page warns you if no backup has been made yet or the newest is more than two days old. That usually means the scheduler is not running.

Each backup includes a file that explains how to restore it. Ask your installer or True-Doctor support to help you restore one.

## Warnings and what they mean

The Hospital Admin may see one of these warnings at the top of every page. None of them stops anyone seeing patients.

| Warning | What to do |
|---|---|
| The scheduler has not checked in recently | Add or check the cron job in cPanel. Reminders, queued email, overnight billing, backups and updates depend on it. |
| The installation licence is missing or invalid | Download licence.json again from your downloads page and contact True-Doctor support. |
| This server address is different from the address on the licence | Change the address on your downloads page, then replace the licence file. |
| New-release entitlement has ended | Your update period is over. Renew it on your downloads page to receive new releases. |

A licence problem can stop updates. It never stops your staff seeing patients or reaching their records.

## Getting help

The **Licence & updates** page has a link, **Trouble with an update, a backup or the licence? WhatsApp us**. Your downloads page has **Need help installing? WhatsApp us**. If someone other than True-Doctor installed your copy, the **Help** menu may point to them instead. See [Troubleshooting and help](17-troubleshooting-and-help.md).
