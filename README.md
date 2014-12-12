HelpScout - Easy Digital Downloads integration
==============================================

This is the server component you need for your Easy Digital Downloads - HelpScout integration. Issues as well as pull
requests are warmly welcomed.

Current version: 0.1

Install
-------

1. Download the [zip file](https://github.com/easydigitaldownloads/EDD-Help-Scout/archive/master.zip) and unzip.
1. Upload the helpscout-edd folder to the root folder of your WordPress install.
1. Copy settings-example.php to settings.php and make sure you define a secret key.

Create your HelpScout app
-------------------------

1. Go to the [HelpScout custom app interface](https://secure.helpscout.net/apps/custom/).
1. Give it an App Name and set the Content Type to Dynamic Content.
1. Give the full URL to the index.php file in your HelpScout dir.
1. Enter the secret key you used in settings.php above.
1. Check the mailboxes you want the app to show up in.
1. Hit Save. You can now test your app.

Changelog
=========

0.1
---

* Initial version.