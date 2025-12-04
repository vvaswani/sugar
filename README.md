# Blood Glucome Monitoring System aka S.U.G.A.R

## Features

- Log blood glucose readings tagging each as fasting, post-prandial or random for better analysis and tracking
- Add personal notes to each reading, like "after exercise" or "woke up feeling low"
- See trends with a line chart of previous readings, by date
- View auto-generated daily and weekly reports, including calculated averages
- Get AI-assisted data analysis, warnings and recommendations (in weekly reports)
- Use on both desktop and mobile, so you can log readings even on the move
- Sign in with your Google account; no extra credentials or passwords to remember

## Useful Commands

### clear and reset database
php bin/console doctrine:database:drop --force
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

### add migrations
php bin/console make:migration

### load dummy data
USER_EMAIL=user@example.com php bin/console doctrine:fixtures:load # purge and add
or
USER_EMAIL=user@example.com php bin/console doctrine:fixtures:load --append # only add

### run workers
php bin/console messenger:consume reports -vv
php bin/console messenger:consume schedule -vv
