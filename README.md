# PDF Auto-Tag

PDF Auto-Tag is a ProcessWire module that uses the Adobe PDF Services API and lets a person send chosen PDF files to Adobe for accessibility tagging. Adobe adds a tag structure so the document can be read by assistive technology. The tagged file, and an optional Excel report, come back to the site for review.

The module does not send every PDF automatically. Auto-tagging rebuilds the tag structure, and that can make a document that was already tagged worse for accessibility purposes. A person has to check a box and save the page, or choose Reprocess on the dashboard, before the file is processed by Adobe.

## What you can do

- Watch selected File fields, including files inside Repeater and Repeater Matrix items.
- Watch a file subfield inside a Combo field, including a Combo that sits inside a repeater.
- On the page editor, offer an unchecked box named “Process with Adobe Auto-Tag” for each watched PDF.
  - Optionally offer a second box, “Also generate the Excel report,” which appears only after the first box is checked.
- Keep a record of each request: who asked, when, and whether Adobe finished, failed, or the file has not yet been sent.
- Review every watched PDF from Setup, then PDF Auto-Tag.
- Download the tagged PDF and its associated Excel report, as provided from Adobe.
- Replace the file in the field with Adobe’s tagged PDF, or restore the previous file from a backup.
  - Optionally make this step automatic in the module configuration.
- Mark a PDF as already accessible so the editor stops offering it. That mark stays on this site and does not call Adobe.
- Optionally email the person who requested a job when it finishes or fails.

## What this module does not do

- It does not call Adobe when someone uploads a file, saves a page, or views a page, unless that person checked the box or chose Reprocess.
- It does not watch image fields.
- It does not watch FormBuilder uploads.
- It does not read the Excel report into the dashboard. The report is a file you download and review.
- It does not decide whether a PDF is accessible. “Already accessible” is a local mark set by a person.

## Requirements

- ProcessWire 3.0.222 or newer. The module uses `WireHttp::put`, which that version provides.
- An Adobe PDF Services client id and client secret for the [Adobe PDF Accessibility Auto-Tag API](https://developer.adobe.com/document-services/docs/overview/pdf-accessibility-auto-tag-api/).
- The PHP process that runs the site, and the PHP process that runs cron, must be able to reach `https://pdf-services.adobe.io`.
- A role permission named `pdf-autotag`. Page-edit permission alone does not show the checkboxes or the dashboard. Superusers can use the module without that permission being assigned.

These are optional:

- LazyCron, if you want the queue to continue during ordinary site requests.
- System cron, if you would rather run the queue on a schedule.
- Repeater, Repeater Matrix, or Combo, only if you watch fields of those types.

Adobe’s own limits still apply. This module refuses a PDF larger than 100 MB before sending it. Adobe may also reject a file because of page count, language, a password, or a damaged file. Adobe allows a limited number of requests per minute. When Adobe answers with “too many requests” (HTTP 429), the job stays requested and is tried again on a later queue run.

## Installation

Installation also installs the Process module that provides the dashboard. It creates:

- The permission `pdf-autotag`.
- The database table `pdf_autotag`.
- The folder `site/assets/pdf-autotag/` (with a rule that blocks direct web access to stored files).
- The email template `site/assets/pdf-autotag/email-template.php`, if that file is not already there.
- A token cache file in `site/assets/cache/PdfAutoTag_token`.

Assign `pdf-autotag` to each role that should request tagging or open the dashboard.

Uninstalling PDF Auto-Tag also uninstalls the dashboard process, and that moves the Setup page to the trash. It does not delete the `pdf_autotag` table or the files under `site/assets/pdf-autotag/`. Remove those yourself if you no longer want the records or the stored PDFs.

## How a request works

1. A person with the `pdf-autotag` permission edits a page that has a watched PDF.
2. Under that file, they check “Process with Adobe Auto-Tag.”
3. If the Excel option is turned on in module settings, a second checkbox appears. They check it only when they want Adobe’s spreadsheet report.
4. They save the page. The save stores a request. It does not wait for Adobe to finish.
5. A later queue run uploads the PDF, asks Adobe to tag it, and checks back until Adobe is done.
6. When Adobe is done, the module downloads the tagged PDF immediately. Signed download links expire, so the file is saved under `site/assets/pdf-autotag/` right away.
7. The dashboard status changes from Requested to Completed, or to Failed if Adobe or the download could not finish.

Checking the box again after a completed or failed job asks for a new run. If a job is already requested and has been sent to Adobe, checking the box again does not start a second copy. If it is requested but not sent yet, saving again updates the report choice, the file fingerprint, and the requester.

A file marked already accessible does not show the checkboxes. Clear that mark on the dashboard if the file should be offered again.

## Dashboard

Open Setup, then PDF Auto-Tag.

The list uses ProcessWire’s admin data table. Select a column heading to sort. On a narrow screen, each cell is shown as its own row with the column name.

| Column       | What it shows                                                                                                              |
| ------------ | -------------------------------------------------------------------------------------------------------------------------- |
| Page         | The page that owns the file. For a repeater or matrix item, this is the page the repeater belongs to.                      |
| Location     | The field, and the Combo subfield name when there is one.                                                                  |
| File         | The PDF name. “File no longer in the field” means the dashboard still has a request, but the PDF is gone from the field.   |
| Requested    | Yes when someone has asked for auto-tag, including after success or failure. No when nobody has asked.                     |
| Status       | Not requested, Requested, Completed, Failed, or Already accessible.                                                        |
| Report       | Yes or No when a request exists and says whether the Excel report was asked for. Blank when nobody has requested the file. |
| Requestor    | The username of the person who last requested the job.                                                                     |
| Created      | When the request was first saved.                                                                                          |
| Last updated | When the request row last changed.                                                                                         |
| Notes        | Adobe errors, download problems, retention messages, and similar notes. Empty when there is nothing to report.             |
| Actions      | The links described below. This column does not sort.                                                                      |

Status stays **Requested** until a queue run sees that Adobe is finished and downloads the file. Adobe can already say “done” while this screen still says **Requested**. Run the queue, or wait for the next scheduled run.

Actions appear only when they apply:

| Action                     | When it is available                                                                            |
| -------------------------- | ----------------------------------------------------------------------------------------------- |
| Download tagged PDF        | A tagged file has been saved for this row.                                                      |
| Download report            | An Excel report has been saved.                                                                 |
| Delete report              | An Excel report has been saved. The job row and tagged PDF stay.                                |
| Reprocess                  | A request exists, the file is still in the field, and it is not marked already accessible.      |
| Mark as already accessible | The file is still in the field and is not already marked.                                       |
| Clear accessible mark      | The file was marked already accessible.                                                         |
| Replace field file         | A tagged PDF exists, the field file is still there, and the two files are not already the same. |
| Restore original           | A backup of the previous field file exists and the field file is still there.                   |

## Module settings

Open the PDF Auto-Tag module configuration.

| Setting                                          | Default    | What it does                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| ------------------------------------------------ | ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Adobe client id                                  | Empty      | Used when `$config->pdfAutoTagClientId` is not set.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| Adobe client secret                              | Empty      | Used when `$config->pdfAutoTagClientSecret` is not set. Clearing the field removes the stored secret.                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| Watched file fields                              | None       | File fields are watched wherever they appear, including inside repeaters. A Combo entry watches that file subfield, including when the Combo is inside a repeater.                                                                                                                                                                                                                                                                                                                                                                                          |
| Shift headings                                   | Off        | When Adobe finds a title, it tags that title as Heading 1 and moves other headings down one level. This is a site-wide setting, not a checkbox on each file.                                                                                                                                                                                                                                                                                                                                                                                                |
| Replace the field file when Adobe finishes       | Off        | Off keeps Adobe’s file beside the record until someone uses Replace field file. On copies the current field PDF aside, then puts the tagged PDF in the field. Turning this on does not call Adobe by itself.                                                                                                                                                                                                                                                                                                                                                |
| Offer the Excel report checkbox                  | On         | On shows “Also generate the Excel report” after the process box is checked. Off hides that box, and saving the page cannot request a report.                                                                                                                                                                                                                                                                                                                                                                                                                |
| Excel report retention (days)                    | 0          | 0 keeps each saved `.xlsx` until someone deletes it. A number greater than 0 deletes the report that many days after it was saved. The job row and tagged PDF stay.                                                                                                                                                                                                                                                                                                                                                                                         |
| Replaced-original retention (days)               | 0          | Applies only to backups made by automatic replace. 0 keeps them until someone restores them. A number greater than 0 deletes those backups that many days after the replace. A backup made with the dashboard Replace action is not deleted by this setting.                                                                                                                                                                                                                                                                                                |
| Email the requester when a job finishes or fails | Off        | Sends mail to the user who requested the job. The message comes from the email template described below.                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| Monthly Adobe API call limit                     | 500        | Maximum calls to the Adobe API in a calendar month. 500 matches the free tier. Each call to `pdf-services.adobe.io` counts, including a token, an asset, starting a job, and a status check. Uploads and downloads on other hosts do not count.                                                                                                                                                                                                                                                                                                             |
| LazyCron interval                                | 30 minutes | How long LazyCron waits between queue checks. The choices are the intervals LazyCron itself provides. This field is hidden when `$config->autoTagUseSystemCron` is true, because system cron follows the crontab schedule instead. Adobe stores files for 24 hours, after that time the files are deleted. Job requests are queued in the system, and some files will take longer than others. In order to not waste API calls on status checks but still provide a fast turnaround, a compromise of 30 minutes is set to the default. Adjust as necessary. |

After an automatic replace, Restore puts the backup back into the field until retention deletes that backup. A later reprocess does not overwrite an existing original backup, because the file in the field may already be the tagged one.

If retention has removed a backup, Restore is not offered. The Notes column says that retention removed it.

## Credentials in site/config.php

Prefer storing the Adobe credentials in `site/config.php`. When both values below are set, they are used instead of the module settings, and the client id and client secret fields are hidden. The settings screen shows a notice that they are set in the site config file.

```php
$config->pdfAutoTagClientId = 'your-client-id';
$config->pdfAutoTagClientSecret = 'your-client-secret';
```

## Queue schedule

The queue only continues work that a person already requested. It also applies report and original-file retention. One run polls up to four jobs that Adobe has already accepted, oldest first, and starts at most one new upload.

Each call to the Adobe API counts toward the monthly limit. The PDF Auto-Tag screen shows the count as `110/500 Monthly Allotment of Adobe API Calls`. Sending one PDF takes a token, an asset, and the auto-tag request. Checking a job later takes a token and a status request. When fewer calls remain than that step needs, the file stays queued. Its status URL is kept, so the next month continues from the same place after the count resets. A job held for the limit is not marked failed.

Adobe’s token response includes `expires_in`, the number of seconds that token stays valid. The module saves the token in the ProcessWire cache and reuses it until one minute before that time. Later queue runs then skip the token call, so sending a PDF takes two calls and checking a PDF takes one. When the saved token is no longer valid, the next run requests a new one. Test credentials always requests a new token. A different client id or secret makes the saved token unused.

### LazyCron

If LazyCron is installed, and system cron is not turned on, the module hooks LazyCron at the interval chosen in module settings. The default is 30 minutes. That interval field is hidden while `$config->autoTagUseSystemCron` is true. LazyCron runs only after some page on the site is requested. A quiet site does not poll Adobe.

### System cron

To use system cron instead of LazyCron, add this to `site/config.php`:

```php
$config->autoTagUseSystemCron = true;
```

That turns the LazyCron hook off. Schedule the command shown on the module settings screen. It looks like this, with your site path:

```cron
*/5 * * * * php /path/to/site/modules/PdfAutoTag/cron.php
```

The command must be run by PHP on the server. A request to `cron.php` from a browser is refused.

If LazyCron is not installed and `$config->autoTagUseSystemCron` is missing or not true, the module settings screen shows a notice. Requested jobs then wait until someone uses the **Run queue now** button from the Dashboard, or until you install LazyCron or turn on system cron.

## Email template

When email is enabled, a finished or failed job includes `site/assets/pdf-autotag/email-template.php`. Installation creates that file. An existing file is left unchanged when the module is updated.

The file is PHP so a customized template can use the ProcessWire API (`wire()`, `pages()`, `$config`, and `$this` as the module). The example template only sets the subject and body.

These variables are available:

| Variable        | Meaning                             |
| --------------- | ----------------------------------- |
| `$ok`           | True when Adobe finished.           |
| `$status`       | `finished` or `failed`.             |
| `$filename`     | The PDF file name.                  |
| `$message`      | A short result sentence.            |
| `$error`        | Failure text, or an empty string.   |
| `$note`         | Extra note, or an empty string.     |
| `$ownerPage`    | The owner page, or null.            |
| `$pageTitle`    | The owner page title.               |
| `$pageUrl`      | The admin editor URL for that page. |
| `$pageBlock`    | The title and editor URL together.  |
| `$dashboardUrl` | The PDF Auto-Tag screen URL.        |
| `$user`         | The requester.                      |
| `$row`          | The database row for the job.       |

Set `$subject` and `$body`. You can instead return an array with `subject` and `body` keys. That return value is used when it is present.

## Where files are stored

Each job stores its files in `site/assets/pdf-autotag/`, in a folder named with the row id:

| File           | Contents                                                 |
| -------------- | -------------------------------------------------------- |
| `tagged.pdf`   | The PDF downloaded from Adobe.                           |
| `report.xlsx`  | The Excel report, when one was requested and downloaded. |
| `original.pdf` | The field file from before a replace.                    |

The folder’s access rule denies direct web requests. Use the dashboard download actions.

## Failure and retry

A failed job stores the error in Notes and can be reprocessed. A rate-limit response does not count as a final failure. The job stays **Requested** so a later queue run can try again.

If the tagged PDF downloads but the Excel report does not, the job is still Completed and Notes explains the report failure. If automatic replace fails after a successful download, the job stays Completed and Notes explains the replace failure.
