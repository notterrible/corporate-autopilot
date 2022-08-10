<?php

// Ping environment to wake up.
file_get_contents('https://' . $_ENV['DRUSH_OPTIONS_URI']);
sleep(60);