# cmprovision utility OS (utility-os/package/cmp-rpi-utils): the tools of raspberrypi/utils it ships; each directory is a
# project of its own
cmake_minimum_required(VERSION 3.10...3.27)
project(cmprovision-rpi-utils C)
add_subdirectory(../dtmerge dtmerge)
add_subdirectory(../pinctrl pinctrl)
add_subdirectory(../vcgencmd vcgencmd)
add_subdirectory(../vcmailbox vcmailbox)
