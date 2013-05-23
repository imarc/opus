# Opus

Opus is a custom composer installer which can be used to faciliate loose package management for assets, framework modules, or configuration code.  While `composer/installers` offers custom installers, these suffer from a few problems which make them fairly limited.

These problems can frequently mean that a maintainer might have to maintain several packaged copies of a single library or that the buck is passed to framework developers to package libraries for their framework.

Opus uses integration packages which provide a simple map to copy files from source packages into your framework or application's folder structure.  These support multiple source and destination directories, cherry picking files, and multiple frameworks in a single package.

## Using Opus Packages in Your Framework/App

- Merge the below JSON into your framework or application's `composer.json` file.
- Add integration packages as you normally would to the `require` object.

```json
"extra": {
	"opus": {
		"enabled": true
	}
}
```

Using the above alone, Opus will match the standard `name` value on your root package to determine which set of installation paths packages should use.  If your `name` value does not match a standard framework or if you've changed the package name for any reason, you can overload the framework name with something like the following:

```json
"extra": {
	"opus": {
		"options": {
			"framework": "<framework>"
		}
	}
}
```

If the above information is set, Opus will use the value of `extra.opus.options.framework` instead of the `name` value on the package to match installation paths.

## Creating an Opus Package

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

When Opus is run, it will examine your root package's framework value (see above) and use the matching `"<source>": "<destination>"` mapping from the list provided.  Your package can support multiple frameworks simply by adding additional map object, keyed by the framework package name.  If no matching framework is found, no action will be taken.

## External Integration Packages

An external integration package is a package which attempts to handle the Opus installation of multiple sub packages.  It does this by wrapping the `"<source>": "<destination>"` mappings in an additional object whose key matches the package names they apply to.  It is additionally useful because you can glob package names.

The diagram below shows an hypothetical integration package which provides code of it's own, but also requires asset files from a hypothetical frontend framework and a third-party library.  In addition to handling it's own Opus mappings it handles that of the asset package as well:

![A diagram showing various packages and Opus integration package for a framework.](https://dl.dropboxusercontent.com/u/31068853/opus.jpg)

## Schema

### Framework/App

```json
"name": "<framework>",
"extra": {
	"opus": {
		"enabled": true|false,               // Enable/Disable Opus
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
"require": {
	"imarc/opus": "*"
},
"extra": {
	"opus": {
		"<framework>": {                     // Framework object
			"<source>": "<destination>",     // Mapping (for this package)
			...
		},
		...                                  // Additional Frameworks and Mappings
	}
}
```

### External Integration Package

```json
"type": "opus-package",
"require": {
	"imarc/opus": "*"
},
"extra": {
	"opus": {
		"<framework>": {                     // Framework object
			"<source>": "<destination>",     // Mapping (for this package)
			"<package>": {                   // External Package Mapping object
				"<source>": "<destination>", // Mapping
				...
			},
			...
		},
		...                                  // Additional Frameworks and Package Mappings
	}
}
```

## Root Package Integrity

Because Opus copies files out of your vendor directory and into your project application or website, there are some checks and balances in place which seek to ensure the integrity of your code.  Let's go through some of the basics here.

### Installation Integrity

During installation operations Opus will raise a conflict in the event a file it is attempting to copy already exists.  This conflict can be responded to directly by the user and will prompt as to whether or not the file should be overwritten or kept.  Additionally, it is possible to quickly view the difference between the files before making a decision.

### Update Integrity

The completion of an installation writes some meta information about the current state of files copied by Opus to an `opus.map` file in the root working directory of your project.  This file is then consulted for information regarding any files that may be overwritten during future updates.

Unlike installation, however, because it is expected that files will exist, and because various projects might have different needs, the integrity is configurable.  As noted above, you can specify an `"integrity"` option on your root package's Opus options with a value of `high`, `medium`, or `low`:

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

- If no file already exists, the new file is copied
- If no non-whitespace differences exist between the existing file and copying file, the  new file is copied
- If differences do exist, the existing file is checksummed and compared to the installation/previous update checksum found in the `opus.map`.  If the value is the same, the new file is copied.
- If the checksum is different (indicating the developer edited the file), the user is presented with conflict resolution options (overwrite, keep, diff)

Medium integrity provides a simple methodology whereby files which are copied and never changed by a developer get automatically updated with a package.  However, if a developer customizes a file a future update will not simply wipe out their changes but instead provide them options as well as the ability to see the differences.

#### High Integrity

High integrity is for the paranoid developer who does not want updates to override anything without their notification.  As such, any conflict results in presenting the developer with conflict resolution options.  Note that this can be extremely tedious for packages which may copy a lot of files, and is generally only preferable if you are seriously concerned with the validity of changes made in upstream packages.

#### Low Integrity

Low integrity, similar to high integrity, makes a simple decision.  Any conflicting file will be overwritten by the updated version.  If you are only using packages which do not require any customization or changes to the copied files, this is probably the most useful setting.  However, you should be aware that sometimes non-code related files copied by packages have important things to say.  For example, if a package copies over a configuration file which you then have to modify in some way, a low setting means that on future updates your configuration file will be overwritten with the default.

## Addendum and Supporting Frameworks

We encourage framework and library developers to begin providing Opus support in their frameworks, web apps, libraries, or asset packages.  If you are a framework maintainer and plan to include Opus, please open an issue on this project and we'll add your namespace(s) and links to the list:

- iMarc Sitemanager [`imarc`]: http://www.imarc.net/
- inKWell 2.0 [`inkwell-2.0`]: http://inkwell.dotink.org/2.0/
