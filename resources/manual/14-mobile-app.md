# The mobile app

The True-Doctor app puts the same system on an Android phone or tablet. It shows the same menus, fields and statuses as the web, limited to what your role allows. Its main strength is that it keeps working when the network drops: you can register patients, take vitals, write notes and enter results with no signal, and the app sends everything when the connection returns.

This chapter explains how to install the app, sign in, work offline and check what is still waiting to send. It ends with the two web pages an administrator uses to look after offline devices: **Offline devices** and **Offline readiness**.

## Installing the app

The app is for Android. You get it from the True-Doctor website: the link **The Android app** is at the bottom of every page.

1. On your phone, open the True-Doctor website and tap **The Android app**.
2. Tap **Get the app**. If you are reading the page on a computer, scan the code on the page with your phone's camera instead.
3. When the file has downloaded, open it. If your phone asks whether to allow installing apps from this source, allow it, then tap install.
4. Open True-Doctor from your phone's app list.

If the page shows **Ask us for the app** instead of a download button, tap it to message the True-Doctor team on WhatsApp and they will send you the app.

## Signing in

The first screen asks **Where would you like to start?** It has these choices:

| Choice | Use it when |
|---|---|
| **Sign in** | Your hospital already uses True-Doctor online. |
| **Try the demo** | You want to look around a sample hospital as a doctor, nurse or cashier. No sign-up. Needs a connection. |
| **Get True-Doctor** | You want to start a free trial for a new hospital. |
| **My hospital runs its own server** | Your hospital has its own copy of True-Doctor on its own server (see [Your own copy](16-your-own-copy.md)). |

To sign in:

1. Tap **Sign in**.
2. Enter your work **Email address** and **Password**. These are the same as on the web.
3. If your hospital runs its own server, tap **Use another server**, type the address your administrator gave you in **Server**, and tap **Check**. The app tells you if the address does not answer.
4. Tap **Sign in**.
5. If your account uses two-step sign-in, the app asks you to **Enter your code**. Open your authenticator app and type the 6-digit code it shows for True-Doctor, then tap **Continue**. If you have lost your phone, type one of your recovery codes instead.
6. If your administrator gave you a temporary password, the app asks you to **Set your password**. Type the password you signed in with, then your new password twice, and tap **Set password & continue**.

If you cannot sign in, tap **Trouble signing in? WhatsApp us** at the bottom of the sign-in screen. Two-step sign-in is explained in [Getting started](01-getting-started.md).

## Finding your way around

The app's menu follows the web menu, so the chapters of this manual apply to the app too. Some pages open as app screens. Pages that have no app screen yet open the same page on the web.

Tap your initials at the top to open the account menu. It has:

- **My account**: your device, offline preparation, storage, app lock and help.
- **Sync**: what is waiting to send and anything that needs your attention.
- **Open on the web**: opens True-Doctor in your phone's browser.
- **Sign out**.

## Locking the app with a PIN

A phone that holds patient records should be locked when you put it down.

1. Open **My account** and find **App lock**.
2. Tap **Set a PIN** and choose a PIN of 4 to 6 digits. Type it again to confirm.

After a couple of minutes away, the app shows **Locked** and asks for your PIN. You can also tap **Lock now** at any time. After five wrong tries the app signs you out. Anything you recorded and had not yet sent stays on the phone and is sent after you sign in again.

If you forget your PIN, tap **Forgot your PIN? Sign out** and sign in again with your password.

The app also signs you out after a period with no activity, as the web does. If you have unsent work and a PIN, it locks instead, so nothing is lost.

## Working offline

The app always keeps a copy of the records you need most: patients, visits, lab work and the ward. These are kept on the phone and synced in the background. You can open them, search them and add to them with no signal.

Other screens, such as the check-in queue, the week's appointments diary, wards and beds, admissions, the stock shelf, stock alerts, invoices, insurance claims and patient cards, keep a copy of what they last showed and say when it was fetched.

### Getting ready before you leave the signal

If you are going somewhere with poor network, such as an outreach or a ward with no signal, prepare the phone first.

1. Make sure you are connected.
2. Open **My account** and find **Working offline**.
3. Tap **Make ready for offline**. The app fetches the screens it needs and shows its progress.
4. When it finishes, the badge shows **Ready** and the card says when it was prepared.

If the card says some screens could not be fetched, connect again and repeat step 3.

### What works offline and what does not

- **Works offline:** registering and updating patients, vitals, nursing notes, medications given, clinical notes, diagnoses and lab results. They are saved on the phone straight away.
- **Needs a connection:** anything about money (invoices, payments and card balances are always worked out on the server), changing your password, setting up two-step sign-in, and starting a free trial. The app tells you when something needs a connection instead of failing quietly.

### The connection badge

A small badge at the top of the screen tells you where you stand:

| Badge | Meaning |
|---|---|
| **Online** | Connected, and nothing is waiting. |
| **Offline** | No connection. Your work is kept on the phone. |
| **Offline · 3 waiting** | No connection, and 3 changes are waiting to send. |
| **3 to send** | Connected, and 3 changes are about to go. |
| **Syncing** | Sending and receiving now. |
| **2 to check** | Something could not be sent and needs you. See below. |

Tap the badge to open the **Sync** screen.

### The phone's clock

Clinical records carry the time they were taken, so the phone's date and time must be right. If the phone's clock is more than an hour away from the server's, the app will not let you record clinical work until you correct the date and time in the phone's settings. If it is a few minutes out, the app warns you.

## Checking what is waiting to send

Open **Sync** from the account menu, or tap the connection badge. The screen shows when the phone last synced, and two counts: **Waiting to send** and **Need your attention**.

- To send now, tap **Sync now**.
- When everything has gone, the screen says **Everything on this device has reached the server.**

Each waiting change is listed with what it is (for example "Vitals round" or "Clinical notes"), when you saved it, and its state: **Waiting**, **Sending**, **Not sent**, **Refused** or **Conflict**.

### When something needs your attention

Changes that could not be sent appear under **Needs your attention**.

- **Not sent:** usually a connection problem. Tap **Try again**, or **Retry all** to retry every one.
- **Conflict:** someone changed the same record on the server while you were offline. The app shows a table with **This device** and **The server** side by side for each field. Tap **Keep mine** to send your version, or **Keep the server's** to accept theirs.
- **Refused:** the server would not accept the change. Read the reason shown, then correct it on the record or discard it.

Some details, such as a date of birth, sex, blood type, allergies and chronic conditions, are never merged automatically, because a wrong value can change treatment. You decide.

**Discard** removes a change for good. The app asks you to confirm, because a discarded change is never sent and cannot be brought back. Only discard something you are sure is wrong or already entered another way.

## Signing out with unsent work

If you tap **Sign out** while changes are still waiting, the app warns you. Signing out does not delete that work: it waits on the phone until you sign in again. It is still better to connect and sync first, especially on a shared phone.

## Keeping the app light

**Storage** on **My account** shows what the phone holds. Tap **Free up space** to clear old saved screens and history. Nothing unsent is ever cleared. The app also tidies itself weekly.

## Updating the app

When a newer version is available, **My account** says so and offers **Download update**. If your app is too old for the server, it shows **This app needs updating** and a **Download the update** button. Anything recorded on the phone is kept and sent once the app is updated.

## Getting help from the app

At the bottom of **My account**, under **Something not right?**:

- **Copy diagnostics** copies a short report about the app (no patient details) so you can paste it into a message.
- **Send to support on WhatsApp** opens WhatsApp with the report ready to send.
- **Save log file** saves the app's log on the phone.

See [Troubleshooting and help](17-troubleshooting-and-help.md) for more.

## For administrators: Offline devices

Every phone and browser that keeps a copy of your hospital's records for offline work is listed under **Administration**, **Offline devices**. You need the role that manages user accounts, normally Hospital Admin.

The top of the page counts **Devices with a copy**, devices **Not seen in 14 days**, **Conflicts waiting** and **Blocked** devices. If changes were refused or could not be applied in the last 7 days, a note says how many. The work is still on the devices that sent it, but someone should look.

Search by device name or by the person using it, and filter by **Working**, **Not seen lately** or **Blocked**. Click a device to see its recent activity.

### Blocking a lost or stolen device

1. Find the device in the list.
2. Open its actions menu and choose **Block this device**.
3. In **Why are you blocking it?**, write the reason, for example "Reported lost on 19 September".
4. Click **Block it**.

A blocked device stops syncing, and its copy of the records is cleared the next time it reaches the server. A device that never connects again keeps what it already has, so treat blocking as a control, not a guarantee. To also stop the person's sessions, see [Security and privacy](15-security-and-privacy.md).

To let a device work offline again, choose **Allow it again** from its actions menu.

## For everyone: Offline readiness

**Administration**, **Offline readiness** is about the computer or browser you are using right now. Any staff member can open it. Use it before you take a laptop somewhere without a connection.

- The page checks the machine and gives a verdict. Click **Prepare this device** to download what you need, or **Download again** to refresh it. **Check again** repeats the checks.
- You can give the machine a name in **Name this machine (optional)**, so an administrator can tell your machines apart.
- **What is downloaded** compares what is on this machine with what is available to you: the patients of the last 30 days and current inpatients, limited to what your role may see. No money is ever downloaded.
- **Machines registered to you** lists your other devices. If you no longer use one, ask an administrator to block it.
- **Remove the copy on this device** deletes the patient records held in this browser. It refuses while anything you recorded is still waiting to be sent, so sync first.

If the page says **The app cannot store anything on this machine**, the browser's settings are blocking storage. Nothing has been lost. Ask your IT person to allow site data for True-Doctor, or use the mobile app.
