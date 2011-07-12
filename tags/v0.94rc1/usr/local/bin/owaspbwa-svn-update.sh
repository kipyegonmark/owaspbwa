#!/bin/sh
echo "---- Stopping services ----"
owaspbwa-services-stop.sh
echo "---- Updating from SVN ----"
svn update --accept theirs-full /owaspbwa/owaspbwa-svn
if [ "$?"-ne 0]; then echo "SVN Update Failed!"; exit 1; fi 
echo "---- Fixing file permissions and restarting services ----"
#when we update the scripts in /usr/local/bin, it may break the permissions
chmod +x /owaspbwa/owaspbwa-svn/usr/local/bin/*.sh
owaspbwa-fix-file-permissions.sh
