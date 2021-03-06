# pg2mysql
  
![](logo.png)

## Index ##

* [About](#about)
* [Setup](#setup)
* [Contributors](#contributors)

## About

Convert/Migrate existing PostgreSQL databases into MySQL.

* Visit [my website](https://jrquick.com) for me cool stuff!

## Setup

To use, simply unzip into your website somewhere, and point your browser at:

	pg2mysql.php

If you want to use your own interface, simply include the pg2mysql.inc.php into 
your own code and call the 'pg2mysql' function.  It accepts one input (the 
postgres code to be converted) and returns the mysql code. eg:

    $mysql = pg2mysql($postgres);


#### Command Line

Command line syntax and options can be viewed using:

    php pg2mysql_cli.php --help


## Contributors

* [Lightbox Technolgoies](http://www.lightbox.org)
* James Grant<james@lightbox.org>
* [Jeremy Quick](https://github.com/jrquick17)

### Donate
* [Lightbox Technolgoies](http://www.lightbox.org)
** Paypal: paypal@lightbox.org
** Bitcoin: 1Gt6D2HcwnoLCeDMkP6xKMC4P65g42TiVn
** Cheque/money order:
    Lightbox Technologies Inc
    240 Catherine St. Suite 312
    Ottawa, ON
    K2P 2G8  CANADA
