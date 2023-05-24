# vCard object model

The initial goal of this repo is to make available a PHP implementation of a vCard version 4.0 object model.

I also plan to implement a Typescript version of this object model.

These are/will be provided under the MIT license.

## PHP implementation

This is a standalone vCard version 4.0 object model.

It can parse previous versions but only creates v4.0 vCards

When parsing, there is little validating being done to accomodate for obsolete properties, parameters, and their values.

It makes no attempts at validating or accepting new iana registered vCard elements, language tags, or mime types, including vendor specific ones. These can be added in the proper validation array constants defined in the VCProperty class, or as new regular expression validations. Please add appropriate tests in vcf.test.php

There is a fair amount of validation when building vCards for v4 compliance but there is no detailed value validation for a number of properties (e.g. XML, MEDIATYPE, ...) and parameters. See the VCProperty for implementation details.

See the vcf.test.php for usage hints
