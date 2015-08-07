Pineapple
=========

Pineapple is a simple UI for the CENDARI semantic repository.

## TODO

More functionality and documentation.

### Development

 - Install composer: `wget -O - https://getcomposer.org/installer | php`
 - Run `php composer.phar update` to install dependencies
 - The app relies on the CENDARI CKAN repository and Virtuoso DB. To make life easier you
   can set up a port forward via SSH, e.g. `ssh [SERVER] -Nv -L8890:localhost:8890 -L42042:localhost:42042`
 - The app _also_ requires Shibboleth authentication to access the CKAN repository. While testing
   while the php development server you can set the `eppn`, `mail`, and `cn` Shibboleth auth parameters
   locally as environment variables and use the `-d variables_order=EGPCS` in php to pass them through
   to `$_ENV` (more [here](http://stackoverflow.com/a/16275594/285374)), for example:

```bash
export cn='Joe Blogs'
export mail=joe.blogs@example.com
export eppn=JoeBlogs@dariah.eu

php -d variables_order=EGPCS -S localhost:8000 # dev server will run at http://localhost:8000
```

More to follow...

