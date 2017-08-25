# Proxy Data Provider
This is proxy data provider component for Yii2. 
 
Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

add

```
"repositories": 
[
        { "type": "vcs", "url": "https://github.com/vistarsvo/proxydataprovider" }
]
```
and
```
"vistarsvo/proxydataprovider": "*"
```

to the require section of your `composer.json` file.

After in config file add
```
'components' => [
    'proxyDataProvider' => [
        'class' => 'vistarsvo\proxydataprovider\ProxyDataProvider',
        'proxyModel' => 'vistarsvo\proxymanager\models\Proxy',
        'randomMemoryCount' => 100
    ],
...
```
 

