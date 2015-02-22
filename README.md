# Opus

## What Does it Do?

Opus copies files and directories from composer packages into your project folder.  These files can
be anything from CSS/JS assets, all the way to plugin code for bootstrapping.

## How Does it Work?

Packages provide installation maps for supporting frameworks which tell Opus which files or
directories to copy from the source package and where to copy them relative to the project root.

## Why Do I Need It?

Opus allows for framework modules or asset libraries to be bundled together.  For example, if you
have a framework which allows for plugins to be installed via the copying of bootstrapping files,
these can be copied automatically for the user.

This is particularly useful if the imported files can be customized or modified since Opus will
ensure the integrity of your changes, however, it can also be used for assets which do not
change except for upstream versions such as JS libraries or CSS.

## Enabling Opus in Your Project's `composer.json` File

_NOTE: Opus will use the `name` property in the `composer.json` to determine which installation map
to use from supporting packages._

In order to enable opus, you will want to require it, and set the `extra.opus.enabled` parameter
to `true`:

```json
"require": {
	"imarc/opus": "*"
},
"extra": {
	"opus": {
		"enabled": true
	}
}
```

## Specifying Installation Map in Packages

Packages can support you framework by creating an installation map which is keyed by the project
name.  If your project is based on or is using an upstream framework but happens to have a
different `name` property in the `composer.json` you can specify the framework as follows:

```json
"extra": {
	"opus": {
		"options": {
			"framework": "<framework>"
		}
	}
}
```

_NOTE: This only has to be done if the `name` in your project's `composer.json` does not match the
supported framework._

## Opus Packages

There are two types of Opus packages:

1. Standard Packages
2. Integration Package

### Standard Packages

A standard package is a regular composer package which has been configured to support one or more
projects or frameworks by providing one or more installation maps keyed by their name.

It has the following information added to the `composer.json`:

```json
"type": "opus-package",
"extra": {
	"opus": {
		"<project / framework>": {
			"<source>": "<destination>",
			...
		},
		...
	}
}
```

When being installed or upgraded, Opus will look at the project's `name` or
`extra.opus.options.framework` if the package has a matching installation map in the `extra.opus`
using the name as the key.  If a matching key is found, Opus will iterate over each property using
the `<source>` key to identify a file or folder location in the package and copy it to the path
referenced by the `<destination>` value in supporting project.

A single package can support multiple frameworks by adding multiple keyed objects to the
`extra.opus` object:

```json
"type": "opus-package",
"extra": {
	"opus": {
		"SomeVendor/CMS": {
			"js/jquery.listview.js": "public/assets/lib/",
			"css/listview.css": "public/assets/styles/views"
		},
		"Acme/Framework": {
			"js/jquery.listview.js": "docroot/js/",
			"css/listview.css": "docroot/css"
		}
	}
}
```

### Integration Packages

Integration packages are a bit like meta packages in composer, although they can also provide some
files themselves.  Principally, an integration package is used in the following circumstances:

1. When a number of third-party packages and/or package assets can or should be combined to
   provide a single installable feature.
2. When existing third-party packages do not support Opus directly.

In short, integration packages allow you to provide installation maps for one or more packages
which do not provide them in their own `composer.json` or where their existing installation map is
needs to be overloaded.

An integration package uses the same format as a standard package, with an additional nested object
keyed by the third-party package name:

```json
"type": "opus-package",
"require": {
	"thirdparty/package": "~1.0"
},
"extra": {
	"opus": {
		"Acme/Framework": {
			"thirdparty/package": {
				"css/listview.css": "docroot/css"
			},
		}
	}
}

```

## Schema

### Framework/Project/Root Package

```json
"name": "<framework>",                       // Used to identify package installation maps
"extra": {
	"opus": {
		"enabled": true|false,               // Enable/disable Opus altogether
		"options": {
			"framework": "<framework>",      // Override package name to specify a framework
			"external-mapping": true|false   // Enable/Disable external integration packages
			"integrity": "high|medium|low"   // Medium is default (see integrity section below)
		}
	}
}
```

### Package with Built-In Opus Support

```json
"type": "opus-package",
"extra": {
	"opus": {
		"<framework>": {                     // Installation map keyed by the framework
			"<source>": "<destination>",     // Source / destination for this package
			...
		},
		...                                  // Additional supported frameworks and mappings
	}
}
```

### Integration Package

```json
"type": "opus-package",
"require": {
	"package": "version"                     // Third-party integrated package
},
"extra": {
	"opus": {
		"<framework>": {                     // Installation map keyed by the framework
			"<source>": "<destination>",     // Source / destination for this package
			"<package>": {                   // Installation map for third-party package
				"<source>": "<destination>", // Source / destination for third-party
				...
			},
			...
		},
		...                                  // Additional supported frameworks and mappings
	}
}
```

## Project Integrity

Because Opus copies files out of your vendor directory and into your project, there are some checks
and balances in place which seek to ensure the integrity of your code.  Let's go through some of
the basics here.

### Installation Integrity

During installation operations Opus will raise a conflict in the event a file it is attempting to
copy already exists.  This conflict can be responded to directly by the user and will prompt as to
whether or not the file should be overwritten or kept.  Additionally, it is possible to quickly
view the difference between the files before making a decision.

### Update Integrity

The completion of an installation writes some meta information about the current state of files
copied by Opus to an `opus.map` file in the root directory of your project.  This file is then
consulted for information regarding any files that may be overwritten during future updates.

Unlike installation, however, because it is expected that files will exist, and because various
projects might have different needs, the integrity is configurable.  As noted above, you can
specify an `"integrity"` option on your project's Opus options with a value of `high`, `medium`,
or `low`:

```json
"name": "<framework>",
"extra": {
	"opus": {
		"options": {
			"integrity": "medium"
		}
	}
}
```

#### Medium Integrity (Default)

Medium project integrity follows a few basic rules:

- If no file already exists, the new file is copied and its checksum is stored in the map.
- If no non-whitespace differences exist between the existing file and copying file, the new file
  is copied and the new file checksum is stored in the map.
- If differences do exist, the new file checksum is compared to the old checksum to determine
  if it has changed in the package itself, if not, no action is taken.
- If it has changed, the checksum in the map is compared to the checksum of the current file to
  see if it has changed on disk, if not, the new file is copied and the checksum is updated.
- If the checksum is different (indicating the developer edited the file), the user is presented
  with conflict resolution options (overwrite, keep, diff)

Medium integrity provides a simple methodology whereby files which are copied and never changed
by a developer get automatically updated with a package.  However, if a developer customizes a file
a future update will not simply wipe out their changes but instead provide them options as well
as the ability to see the differences.

#### High Integrity

High integrity is for the paranoid developer who does not want updates to override anything
without their notification.  As such, any conflict results in presenting the developer with
conflict resolution options.  Note that this can be extremely tedious for packages which may copy
a lot of files, and is generally only preferable if you are seriously concerned with the validity
of changes made in upstream packages.

#### Low Integrity

Low integrity, similar to high integrity, makes a simple decision.  Any conflicting file will be
overwritten by the updated version.  If you are only using packages which do not require any
customization or changes to the copied files, this is probably the most useful setting.  However,
you should be aware that sometimes non-code related files copied by packages have important things
to say.  For example, if a package copies over a configuration file which you then have to modify
in some way, a low setting means that on future updates your configuration file will be overwritten
with the default.

## Addendum and Supporting Frameworks

We encourage framework and library developers to begin providing Opus support in their frameworks,
web apps, libraries, or asset packages.  If you are a framework maintainer and plan to include Opus,
please open an issue on this project and we'll add your namespace(s) and links to the list:

- iMarc Application Base and Sitemanager [`imarc/app-base`]: http://www.imarc.net/
- inKWell 3.0 [`inkwell/framework`]: http://inkwell.dotink.org/
- inKWell 2.0 [`dotink/inkwell-2.0`]: https://github.com/dotink/inkwell-2.0
