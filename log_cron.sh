#!/bin/bash
name="log_"`date +"%d_%m_%Y"`".tar.gz"
if tar cfz $name log;
then 
find log -name "*.log" -delete
fi