# Security

Laravel Integrity reads source files and JSON configuration; it does not boot
the inspected application. Run it with the same filesystem permissions used
for ordinary source analysis. Report path-containment or parser denial-of-
service issues privately to the repository owner before disclosure.
