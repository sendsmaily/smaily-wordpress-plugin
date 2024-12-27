#!/bin/bash

# Get user and group information using id command
user_info=$(id -un)
user_id=$(id -u)
group_info=$(id -gn)
group_id=$(id -g)

# Create .env only if it doesn't exist.
if [ ! -f ".env" ]; 
then
    touch .env
else
    echo ".env file exists, exiting."
    exit 0
fi

printf "USER=%s\n" "$user_info" >> .env
printf "USER_ID=%s\n" "$user_id" >> .env
printf "GROUP=%s\n" "$group_info" >> .env
printf "GROUP_ID=%s\n" "$group_id" >> .env

echo "Variables ensured in .env file."

exit 0
