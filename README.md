# Terminus Plugin LCM Deploy

A plugin for deploying to Test and Live environments on Pantheon. This plugin differs from the `env:deploy` command because it first checks if there is overridden configuration and optionally stops the deployment if there is.

## Usage

```
terminus safe-deploy:deploy <site.env>
```

There are a number of options available for this command:
- `--force-deploy`: Deploy even if there are configuration overrides on the target environment.
- `--with-cim`: Run a configuration import after deployment
- `--with-updates`: Run database updates after deployment. Updates are run after config import, if that option is included.
- `--clear-env-caches`: Clear Pantheon environment cache after deploy
- `--with-backup`: Takes a backup prior to initiating the deployment
- `--slack-alert`: Adds ability to alert a channel in slack on the success/failure of deployment

## Installation
```
terminus self:plugin:install lastcallmedia/terminus-safe-deploy
```
Or you can clone it and install from local:

```
git clone https://github.com/LastCallMedia/terminus-safe-deploy.git SOME_DIRECTORY
terminus self:plugin:install SOME_DIRECTORY
```

## Slack
This command can notify a slack channel on the success/failure of a deployment. In order to do this, you must do two things:
- Use the `--slack-alert` flag when running the command.
- Have a `SLACK_URL` environment variable that stores the url used to post to the desired channel.
    - This was implemented this way because organizationally we store the slack url as a variable in GitHub Actions. Future release will include the option to use a `--slack-url` option and specify the url that way.

## Docker
A docker image with the plugin preinstalled can be found [here](https://hub.docker.com/repository/docker/lastcallmedia/terminus-safe-deploy/general). Whenever a new tag is pushed to the repository, a
matching tag is pushed to docker hub.

Usage:
```
docker run lastcallmedia/terminus-safe-deploy:TAG terminus safe-deploy <site.env>
```

### Docker in GitHub Actions
If using this image for your GitHub Actions you will need to copy the Terminus configuration to the `github` user's home directory. [See Example](https://github.com/LastCallMedia/github-actions/blob/1.0.0/.github/workflows/terminus-safe-deploy.yml#L79-L81).
