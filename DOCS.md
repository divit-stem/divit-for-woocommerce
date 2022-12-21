# divit payment gateway for woocommerce

[more docs](https://developer.divit.com.hk/docs/platforms/woocommerce/overview)

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

### deploy to wordpress.org

- [setup SVN for deploy](https://wpmudev.com/blog/wordpress-subversion/)
- update version in `payment-gateway.txt` > `Version` and `DIVIT_PLUGIN_VERSION`
- update version in `readme.txt` > `Stable tag`
- copy and replace all files to `SVN folder` > `truck`
- create new folder and copy all files to `SVN folder` > `tags` > `new version`
- deploy asing jVN with wordpress member account