.. ==================================================
.. FOR YOUR INFORMATION
.. --------------------------------------------------
.. -*- coding: utf-8 -*- with BOM.

.. include:: ../Includes.txt
.. include:: Images.txt

.. _administration:

Administration
==============

.. only:: html

How to get pw_comments
----------------------
There are two ways to get pw_comments. You can visit the TER at
http://typo3.org/extensions/repository/view/pw_comments/current/ and download pw_comments there or you can use
SVN at https://svn.typo3.org/TYPO3v4/Extensions/pw_comments/ to check out the latest version. The trunk folder will
always contain the current development state, but in the tags folder you'll find the different releases.

Installation
------------
After loading the extension onto the server, you will have to install it like every other extension. The installation
will create a new table for storing comments. After the installation you should add the static include to your current
TYPO3 template:

|comment_static-includes|

The Main Static Template must be included. The Styles are optional, but recommended. When you are using TYPO3 7.6 there
is also a "Style Fix" available, which you need to add after the optional styles, in order make icons in frontend work
(Background: TYPO3 changed the depth of asset files in 8.7).

When the statics have been added you can access several libs to include pw_comments into your page
(as described in the chapter :ref:`configuration`).