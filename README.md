# Opus

Opus is a custom composer installer which can be used to faciliate loose package management for assets, framework modules, or configuration code.  While `composer/installers` offers custom installers, these suffer from a few problems which make them fairly limited.

These problems can frequently mean that a maintainer might have to maintain several packaged copies of a single library or that the buck is passed to framework developers to package libraries for their framework.

Opus uses integration packages which provide a simple map to copy files from source packages into your framework or application's folder structure.  These support multiple source and destination directories, cherry picking files, and multiple frameworks in a single package.

## Using Opus Packages in Your Framework/App

- Merge the below JSON into your framework or application's `composer.json` file.
- Replace the value of `extra.opus.options.framework` with the name of your framework or application.
- Add integration packages as you normally would to the `require` object.

```json
	"extra": {
		"opus": {
			"options": {
				"framework": "<framework>"
			}
		}
	}
```

## Creating Opus Package

You can add Opus support to your existing packages by adding the following JSON to their `composer.json` file, replacing fields in `<>` with the appropriate values.

```json
	"type": "opus-package",
	"require": {
		"imarc/opus": "*"
	},
	"extra": {
		"opus": {
			"<framework>": {
				"<source>": "<destination>",
				...
			},
			...
		}
	}
```

When Opus is run, it will examine your packages for the `opus` object and determine if there is a matching framework object.  The framework name should match the `extra.opus.options.framework` value in your root package / framework / application's `composer.json`.  If this value matches, it will copy everything indicated by the source values on the left to the destination values on the right.  These can be entire folders or single files.

## External Integration Packages

An external integration package is a package which attempts to handle the Opus installation of multiple sub packages.  It does this by wrapping the `"<source>": "<destination">` mappings in an additional object whose key matches the package names they apply to.  It is additionally useful because you can glob package names.

The diagram below shows an hypothetical integration package which provides code of it's own, but also requires asset files from a hypothetical frontend framework and a third-party library.  In addition to handling it's own Opus mappings it handles that of the asset package as well:

![A diagram showing various packages and Opus integration package for a framework.](https://dl.dropboxusercontent.com/u/31068853/opus.jpg)

## Schema

### Framework/App

```json
"extra": {
	"opus": {
		"options": {
			"framework": "<framework>",      // name of the framework or application
			"external-mapping": true|false   // whether or not to support external mapping
		}
	}
}
```

### Package with Built-In Opus Support

```json
"type": "opus-package",
"require" {
	"imarc/opus": "*"
},
"extra": {
	"opus": {
		"<framework>": {                     // framework object
			"<source>": "<destination>",     // source => destination mapping
			...
		},
		...                                  // Additional framework objects
	}
}
```

### External Integration Package

```json
	"type": "opus-package",
"require" {
	"imarc/opus": "*"
},
"extra": {
	"opus": {
		"<framework>": {                     // framework object
			"<package>": {                   // package object (can do glob matching)
				"<source>": "<destination>", // source => destination mapping
				...
			},
			...
		},
		...                                  // Additional framework objects
	}
}
```

## Addendum and Supporting Frameworks

We encourage framework and library developers to begin providing Opus support in their frameworks, web apps, libraries, or asset packages.  If you are a framework maintainer and plan to include Opus, please open an issue on this project and we'll add your namespace(s) and links to the list:

- iMarc Sitemanager [`imarc`]: http://www.imarc.net/
- inKWell 2.0 [`inkwell-2.0`]: http://inkwell.dotink.org/2.0/
