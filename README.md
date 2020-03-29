Correction (module for Omeka S)
===============================

[Correction] is a module for [Omeka S] that allows visitors to correct, complete
or translate metadata of the resources without access to the admin board. Access
to the edit page may be controlled by a token, that you can send to your users.


Installation
------------

This module requires the module [`Generic`] (version >= 3.0.16) installed first.

See general end user documentation for [installing a module].

* From the zip

Download the last release [`Correction.zip`] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `Correction`.


Usage
-----

- Configure the resource templates to select the properties to be corrected or
  filled.
- For the resources without template, configure the properties to correct by
  default in the main parameters.
- Create one or more tokens for the resources you want to correct via the link
  in the sidebar of a resource or the bulk process dropdown at the top of the
  resource browse pages.
- Send emails to your users with the tokens, so they can correct or complete
  metadata of the resources.
- Go to the resource page of the corrected items and apply changes, or decline
  them. Corrections can be marked as reviewed and token can be made expired.


TODO
----

- Make the token optional (allow anybody to edit).
- Manage the fillable fields with a language, so it will simplify validation of
  translation.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitHub.


License
-------

This module is published under the [CeCILL v2.1] licence, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

* Copyright Daniel Berthereau, 2019 (see [Daniel-KM] on GitHub)

First version of this module was done for [Université de Paris-Saclay].


[Correction]: https://github.com/Daniel-KM/Omeka-S-module-Correction
[Omeka S]: https://omeka.org/s
[`Generic`]: https://github.com/Daniel-KM/Omeka-S-module-Generic
[`Correction.zip`]: https://github.com/Daniel-KM/Omeka-S-module-Correction/releases
[Installing a module]: http://dev.omeka.org/docs/s/user-manual/modules/#installing-modules
[module issues]: https://github.com/Daniel-KM/Omeka-S-module-Correction/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Université de Paris-Saclay]: https://www.universite-paris-saclay.fr
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
