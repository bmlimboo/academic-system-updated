# Examination dropdown update

Adds End-semester Exam, Mid-semester Exam, Special Exam and Re-assessment Exam. These choices appear on the public submission page even before any period has been published. Existing Semester-end/Mid-semester/Special examination records retain their data and display the new labels. A period still needs to be created and published by Exam Cell before uploads open.

No database migration or cron change is needed. This small patch requires the Exam Cell Portal update to be installed already.

On your Mac:

```bash
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-exam-options-update.zip root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

On Ubuntu, run each block separately and stop if a command fails:

```bash
cd /var/www/academic-system
tar -czf "/root/cnr-exam-options-backup-$(date +%Y%m%d-%H%M%S).tar.gz" src/exam-portal.php src/exam-view.php
unzip -o /tmp/cnr-exam-options-update.zip -d /tmp/cnr-exam-options-update
php -l /tmp/cnr-exam-options-update/src/exam-portal.php
php -l /tmp/cnr-exam-options-update/src/exam-view.php
```

Both syntax checks must pass. Then:

```bash
install -o root -g www-data -m 644 /tmp/cnr-exam-options-update/src/exam-portal.php /var/www/academic-system/src/exam-portal.php
install -o root -g www-data -m 644 /tmp/cnr-exam-options-update/src/exam-view.php /var/www/academic-system/src/exam-view.php
systemctl reload apache2
```

Refresh the browser. Select an examination type and click View. If no published period exists, the page explains that submissions are not available yet.

To open submissions: official login → Exam Cell Portal → Examination dashboard & archive → create a draft with academic year, semester, one of the four examination types, opening date/time and deadline → select the draft → Publish submission period. Publishing queues the opening announcement when its opening time is reached. Do not publish a test period unless you intend to notify active teaching staff.
