# divit payment gateway for woocommerce

[more docs in developer portal](https://developer.divit.com.hk/docs/platforms/woocommerce/overview)

### setup local server for development

- mac developer download [XAMPP](https://www.apachefriends.org/) for hosting wordpress in local
- put this project in `wp-content` > `plugins`
- change code will affect the local site

### features

- setup merchant
- select campaign
- checkout
- express checkout
- order expired callback
- divit promoption code
- display miles labels
- single sign on
- autofill address if logged in

### translation

- install wp-cli `brew install wp-cli`
- generate new pot file `wp i18n make-pot . languages/wc-divit-payment-gateway.pot` 
- install [Poedit] to load pot file and generate .po, .mo content
- .po file naming should be `wc-divit-payment-gateway-{lang_code}.po`
- if you want to update .po file after updated .pot, select menu -> translation -> update from pot file

[Poedit]: https://poedit.net/

### debugging

1. use `self::log()` function to log message / object
1. then see log in "WooCommerce > System Status > Logs" in the WordPress admin

### deploy for manual install

1. update version in `payment-gateway.php` > `Version` and `DIVIT_PLUGIN_VERSION`
1. update version in `readme.txt` > `Stable tag`
1. write changelog in `readme.txt` > `== Changelog ==`
1. zip all files as name: `divit-for-woocommerce.zip`
1. NOTE: by using same zip filename, admin can replace the existing installed one.

### deploy to wordpress.org 

Setup:

1. [setup SVN for deploy](https://wpmudev.com/blog/wordpress-subversion/)
1. mac developer install [SmartSVN](https://www.smartsvn.com/)
1. select `checkout with remote repository`: https://plugins.svn.wordpress.org/divit-rewards-and-payment-gateway

Deploy:

1. update version in `payment-gateway.php` > `Version` and `DIVIT_PLUGIN_VERSION`
1. update version in `readme.txt` > `Stable tag`
1. write changelog in `readme.txt` > `== Changelog ==`
1. NOTE: you make need to remove `DOCS.md` after you copy files
1. copy and replace all files to `SVN folder` > `truck`
1. create new folder and copy all files to `SVN folder` > `tags` > `new version`
1. deploy using SVN client with wordpress member account
1. visit and check [divit and payment gateway](https://wordpress.org/plugins/divit-rewards-and-payment-gateway/) for updates correctly