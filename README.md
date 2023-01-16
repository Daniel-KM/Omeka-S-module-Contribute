Contribute (module for Omeka S)
===============================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Contribute] is a module for [Omeka S] that allows visitors to add, edit,
complete or translate metadata of the resources without access to the admin
board.

Access to the contribute and to the edit page may be controlled by a token, that
you can send to your users, or to users only (in particular guest users), or to
anybody.

The two main differences with module [Collecting] is the fact that this module
uses standard resource templates, so you don't need to create specific forms,
and the possibility to complete or to revise an existing document.


Installation
------------

The module uses the module [Advanced Resource Template] in order to manage the
forms and the properties that the users can edit or fill, so it should be
installed first.

Install the optional module [Generic] (version >= 3.3.35) too if wanted.

If you want to open contribution only to authenticated users, it is recommended
to install the module [Guest] and [Blocks Disposition] (unless you edit theme).

See general end user documentation for [installing a module].

* From the zip

Download the last release [Contribute.zip] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `Contribute`.


Usage
-----

To be contributed or completed, a resource must have a template and be allowed
in the main settings.

- Configure the main settings, in particular the contribution mode.
- Configure the resource templates to select the properties to be edited or
  filled. Note: If a property has no value, it can't be edited, even if the
  property is marked editable. To allow to add a value, choose "fillable". To
  allow only one value, set the maximum number of values to 1.
- If the contribute mode requires token:
  - Create one or more tokens for the resources you want to edit via the link in
    the sidebar of a resource or the bulk process dropdown at the top of the
    resource browse pages.
  - Send emails to your users with the tokens, so they can edit or complete
    metadata of the resources.
- Else a link is displayed on the item page if enabled in the theme or via the
  module Blocks Disposition.
- After submission, the admin can go to the resource page of the edited items
  and apply changes, or decline them. A page lists all contributions too.
  Contribution can be marked as reviewed and token can be made expired.


TODO
----

- [x] Reintegrate features for corrections in version 3.3.0.18+.
- [ ] Store the site in the contribution.
- [ ] Store the ip and some data to check anonymous contribution (see module Contact Us).
- [x] Make the token optional (allow anybody to edit; review all rights).
- [ ] Manage the fillable fields with a language, so it will simplify validation of translation (use advanced resource template).
- [ ] Finalize value resources.
- [ ] Finalize select for resources (dynamic api query via chosen-select).
- [x] Create an admin browse page with all contributes.
- [ ] Remove the fallback contribution settings to simplify config and move all settings to advanced resource template.
- [ ] Check process when the resource template is updated (required, max values, editable, fillable…).
- [ ] Remove requirement for Advanced Resource Template: only list of editable and fillable properties may be needed (or make them all editable/fillable).
- [ ] Remove the "@" in internal proposition values.
- [ ] Add the elements via the form, not only via view.
- [ ] Add resource via token (only edition currently).
- [ ] Clarify add/edition resource for correction.
- [ ] Dynamic select for resource (without custom vocab).
- [ ] Add pagination in guest contribution list.
- [ ] Require only on submit for new contribution?
- [ ] Improve lang management.
- [ ] Allow to create subresource in the main form (author). Require check.
- [ ] Manage value annotations.
- [ ] Include all fields as form elements (included laminas collections of elements).


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
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

* Copyright Daniel Berthereau, 2019-2023 (see [Daniel-KM] on GitLab)

First version of this module was done for [Université de Paris-Saclay].
Improvements were done for [Enssib] and for the site used to do the deposit and
the digital archiving of student works ([Dante]) of the [Université de Toulouse Jean-Jaurès].


[Omeka S]: https://omeka.org/s
[Contribute]: https://gitlab.com/Daniel-KM/Omeka-S-module-Contribute
[Collecting]: https://omeka.org/s/modules/Collecting
[Advanced Resource Template]: https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedResourceTemplate
[Blocks Disposition]: https://gitlab.com/Daniel-KM/Omeka-S-module-BlocksDisposition
[Generic]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generic
[Guest]: https://gitlab.com/Daniel-KM/Omeka-S-module-Guest
[Contribute.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-Contribute/-/releases
[Installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Contribute/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Université de Paris-Saclay]: https://www.universite-paris-saclay.fr
[Enssib]: https://www.enssib.fr
[Dante]: https://dante.univ-tlse2.fr
[Université de Toulouse Jean-Jaurès]: https://www.univ-tlse2.fr
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
