#! /usr/bin/perl -w
use strict;
use YAML;
my $file = shift;
for ( `/bin/cat $file` ){
	s/\s\d+:/,/g and print "$_";
}
# vi:nowrap:sw=4:ts=4
