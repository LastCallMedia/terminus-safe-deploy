FROM composer
# Accept a tag argument to define which version of the safe-deploy plugin to pull.
ARG plugin_tag
WORKDIR /usr/local/bin
ADD https://github.com/pantheon-systems/terminus/releases/download/3.1.2/terminus.phar /usr/local/bin/terminus
RUN chmod +x terminus

# Install the plugin at the specified tag.
RUN terminus self:plugin:install lastcallmedia/terminus-safe-deploy:$plugin_tag

# Verify that the plugin is installed.
# This is needed because terminus self:plugin:install returns a 0 exit code even if
# the plugin does not correctly install.
RUN terminus self:plugin:list --field=name | grep "terminus-safe-deploy"
