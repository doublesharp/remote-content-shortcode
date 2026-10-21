FROM wordpress:cli-php8.2
USER root
COPY xdebug.tar.gz /work/xdebug.tar.gz
RUN apk add --no-cache --virtual .coverage-build $PHPIZE_DEPS linux-headers \
    && cd /work && tar -xzf xdebug.tar.gz && cd xdebug-3.5.3 \
    && phpize && ./configure --enable-xdebug && make -j4 && make install \
    && docker-php-ext-enable xdebug && rm -rf /work/xdebug* && apk del .coverage-build
ENV XDEBUG_MODE=off

COPY uopz.tar.gz /work/uopz.tar.gz
RUN apk add --no-cache --virtual .fault-build $PHPIZE_DEPS linux-headers \
    && cd /work && mkdir uopz && tar -xzf uopz.tar.gz -C uopz --strip-components=1 \
    && cd uopz && phpize && ./configure --enable-uopz && make -j4 && make install \
    && docker-php-ext-enable uopz && printf 'uopz.disable=1\nuopz.exit=1\n' > /usr/local/etc/php/conf.d/uopz-settings.ini \
    && rm -rf /work/uopz* && apk del .fault-build

COPY vld.tar.gz /work/vld.tar.gz
COPY coverage-vld.patch /work/coverage-vld.patch
# Allow the disposable test to enable the observer after WordPress boots.
# The compiler passes through while inactive; opcode dumping is unchanged.
RUN apk add --no-cache --virtual .opcode-build $PHPIZE_DEPS linux-headers patch \
    && cd /work && mkdir vld && tar -xzf vld.tar.gz -C vld --strip-components=1 \
    && cd vld && patch -p1 < /work/coverage-vld.patch \
    && sed -i 's/PHP_INI_SYSTEM/PHP_INI_ALL/g' vld.c \
    && phpize && ./configure --enable-vld && make -j4 && make install \
    && docker-php-ext-enable vld && rm -rf /work/vld* /work/coverage-vld.patch && apk del .opcode-build
