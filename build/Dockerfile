FROM ubuntu:22.04

# Install wkhtmltopdf 0.12.6.1-3 with patched Qt (required for predictable PDF output)
# This is the final release — wkhtmltopdf project is archived
# See: https://github.com/wkhtmltopdf/packaging/releases/tag/0.12.6.1-3
RUN apt-get update && apt-get install -y --no-install-recommends \
    wget \
    xfonts-75dpi \
    xfonts-base \
    fontconfig \
    libfontconfig1 \
    libfreetype6 \
    libjpeg-turbo8 \
    libx11-6 \
    libxcb1 \
    libxext6 \
    libxrender1 \
    libpng16-16 \
    libssl3 \
    libstdc++6 \
    zlib1g \
    && wget -q https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.jammy_amd64.deb \
    && dpkg -i wkhtmltox_0.12.6.1-3.jammy_amd64.deb \
    && rm wkhtmltox_0.12.6.1-3.jammy_amd64.deb \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Verify installation
RUN wkhtmltopdf -V

ENTRYPOINT ["wkhtmltopdf"]
