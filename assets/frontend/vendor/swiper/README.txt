Swiper - bundled third-party library
====================================

Why these files exist
---------------------
The slider layout uses Swiper. WordPress.org requires that any compiled or
minified code shipped in a plugin have its human-readable source available
(Detailed Plugin Guideline 4), including bundled third-party libraries. Both the
minified files that are actually enqueued and the unminified originals they were
built from are therefore shipped side by side.

Version
-------
Swiper 11.2.6 - vendored as published; see "Files" for the two comments our build adds.

Upstream
--------
Project : https://swiperjs.com/
Source  : https://github.com/nolimits4web/swiper
Package : https://www.npmjs.com/package/swiper/v/11.2.6

Files
-----
swiper-bundle.js       - unminified source (upstream, unmodified)
swiper-bundle.css      - unminified source (upstream, unmodified)
swiper-bundle.min.js   - minified build, as published upstream (this is enqueued)
swiper-bundle.min.css  - minified build, as published upstream (this is enqueued)
LICENSE                - Swiper's MIT license

The .min.* files are the ones published in the upstream npm package - they are not
produced by our build. Our build adds two things to each .js and .css file here and
changes nothing else: a comment block at the very top, and one comment at the start of
the first line of code, both beginning "/*! Plugixa Testimonial -- bundled third-party
library" and naming the upstream project. With those two comments removed, each file's
SHA-256 hash matches the file in swiper@11.2.6 byte for byte, so it can be verified
against upstream directly.

How to reproduce / update
-------------------------
    npm pack swiper@<version>
    tar -xzf swiper-<version>.tgz
    cp package/swiper-bundle.js package/swiper-bundle.css \
       package/swiper-bundle.min.js package/swiper-bundle.min.css \
       package/LICENSE  assets/frontend/vendor/swiper/

License
-------
Swiper is released under the MIT License (see LICENSE in this directory), which is
GPL-compatible, so it may be redistributed with this GPL-2.0-or-later plugin.
