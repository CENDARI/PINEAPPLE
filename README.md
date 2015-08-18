[![Build Status](https://travis-ci.org/CENDARI/PINEAPPLE.svg?branch=master)](https://travis-ci.org/CENDARI/PINEAPPLE)

Pineapple
=========

Pineapple is a simple UI for the CENDARI semantic repository.

### Development

 - Install composer: `wget -O - https://getcomposer.org/installer | php`
 - Run `php composer.phar update` to install dependencies
 - Compile the css file from sass (using the Leafo compiler):
 
```
vendor/leafo/scssphp/bin/pscss -f compressed -i .:sass sass/styles.scss > stylesheets/styles.css
```
 
 - The app relies on the CENDARI CKAN repository and Virtuoso DB. To make life easier you
   can set up a port forward via SSH, e.g. `ssh [SERVER] -Nv -L8890:localhost:8890 -L42042:localhost:42042`
 - The env var `APP_DEBUG` being set to `true` will prevent the Twig templates from caching, which you do
   not want when editing them.
 - The app _also_ requires Shibboleth authentication to access the CKAN repository. While testing
   while the php development server you can set the `eppn`, `mail`, and `cn` Shibboleth auth parameters
   locally as environment variables and use the `-d variables_order=EGPCS` in php to pass them through
   to `$_ENV` (more [here](http://stackoverflow.com/a/16275594/285374)), for example:

```bash
export cn='Joe Blogs'
export mail=joe.blogs@example.com
export eppn=JoeBlogs@dariah.eu
APP_DEBUG=true

php -d variables_order=EGPCS -S localhost:8000 # dev server will run at http://localhost:8000
```

### API

The app will respond with JSON if:

 - the `Accept` header contains `application/json` 
 - a `format` parameter is set to `json`
 
Typical responses are as follows:

```bash
http http://localhost:8000 Accept:application/json q=='Amiens Cathedral'
```

```json
{
    "limit": 20,
    "offset": 0,
    "query": "Amiens Cathedral",
    "resources": [
        {
            "id": "bcc950c1-6984-4f9a-802d-3571d04d0adf",
            "lastModified": "1419000522000",
            "numMentions": 15,
            "title": "View inside Amiens Cathedral"
        },
        ...
    ]
}
```

```bash
http localhost:8000/resource/bcc950c1-6984-4f9a-802d-3571d04d0adf Accept:application/json
```

```json
{
    "id": "bcc950c1-6984-4f9a-802d-3571d04d0adf",
    "title": "View inside Amiens Cathedral",
    "lastModified": "1419000522000",
    "plainText": "...",
    "source": "c1-6984-4f9a-802d-3571d04d0adf",
    "mentions": [
        {
            "title": "France",
            "type": "schema:Place",
            "uri": "cendari://resources/Place/France"
        },
        ...
    ]
}
```

```bash
http localhost:8000/mention/schema:Place/France Accept:application/json
```

```json
{
    "limit": 20,
    "mentions": [
        {
            "id": "71a62833-7c4d-41a5-90aa-6f047eafd4c6",
            "title": "One of our big guns with which we annoy the enemy"
        },
        ...
    ],
    "name": "France",
    "type": "schema:Place"
}
```

### Testing

To run the tests (few that there are) run:

```bash
./vendor/phpunit/phpunit/phpunit tests
```

### TODO

 - Lots more functionality
 - More tests

