# Mautic double-opt-in (DOI) plugin

Adds a robust and flexible way to add a double-opt-in process (DOI) to any form in Mautic.


## What is the plugin for?

In short: It helps you to implement a simple and reliable method to collect opt-ins.

- Manange the opt-in status for your contacts in Mautic
- Automatically send a confirmation email when the contact has not opted in yet
- Track the click on confirmation link safely
- Add / remove tags and segments in case of successfull confirmation


## Features

- Multi Campaign Support: Track Opt Ins across multiple topics / subscribtions for every contact
- Cryptografically protected confirmation links
- Audit log for proof of opt-in collection


## How to Install

The current version was tested with Mautic 3.3. Support for Mautic 4 is on the way.

1. Donwload the ZIP file
2. Extract it to a local directory
3. Upload the contents to your Mautic into /plugins
4. Navigation to the Plugins page
5. Click "Refresh / Install plugin"

Done! The plugin should now appear in the list of plugins.



## How to Create an Opt-In Form

Start by creating a new email first. You need to add a special token, that will be replaced with the URL that the
contact must click to confirm her will to opt-in.

Add this token:

````
{doi_url}
````

Otherwise, your email don't need to be special in any way. 

Next create a new form. In the actions section, add a new action "Manage DOUBLE OPTIN (DOI) confirmation via email". 
This is the action provided by the plugin.

In the settings of the action you can now select the email and what tags and segments should be used.

That's it!


## Known Issues

- The audit log is only accessible via the database at the moment


## FAQ

F: How do I manage multiple campaigns?<br>
A: You can simply use a specific combination of tags for every campaign that you want to have its own DOI.

F: When will the email be sent?<br>
A: At the moment, the email will be sent every time the form is submitted.

We think about ways to improve this. Maybe we should only send the email when the contact does not have the tags, that 
we would add in the success case?

F: How do you define a "successfull" opt-in?<br>
When the user clicks the confirmation link, we assume that this is an success. 

F: What must the email look like?<br>
There are no special requirements, just add the merge tag {doi_url} to an link.


## Credits

This plugin is made available by Content Optimizer GmbH. It was developped by Sebastian Fahrenkrog.

