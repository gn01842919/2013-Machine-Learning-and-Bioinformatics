#!/usr/bin/perl -w

# depedent modules
use strict;
# associated modules
sub BEGIN { push @INC, $0; $INC[$#INC] =~ s/[^\/]+$//; }
use mlb_eval;

my $bin        = shift;
my $dir        = shift;
my $select_fn  = shift;
my $feature_fn = shift; 
my $stock_dir  = shift;
my $classifier = shift;
my $type	   = shift;

my $select_cmd  = &make_cmd($select_fn);
my $feature_cmd = &make_cmd($feature_fn);
`$select_cmd train $stock_dir/ > $dir/tr`; 
`$feature_cmd train $dir/tr $dir/tr.info $dir/tr.svm`; 
`$bin/svm-scale -s $dir/tr.scale $dir/tr.svm > $dir/tr.svm.scale`;
if ( 'rvkde' eq $classifier ) {
	if ( 'classification' eq $type ) {
		`$bin/rvkde --best --cv --classify -n 5 -v $dir/tr.svm.scale | /usr/bin/head -3 | /usr/bin/tail -1 > $dir/rvkde-arg`;
	}elsif ( 'regression' eq $type ) {
		`$bin/rvkde --best --cv --regress -n 5 -v $dir/tr.svm.scale | /usr/bin/head -2 | /usr/bin/tail -1 > $dir/rvkde-arg`;
	}
	print `/bin/cat $dir/rvkde-arg`;
} elsif ( 'libsvm' eq $classifier ) {
	if ( 'classification' eq $type ) {
		`$bin/grid.py -svmtrain $bin/svm-train -log2c 0,6,2 -log2g -6,0,2 -v 5 -out $dir/tr.svm.scale.out  $dir/tr.svm.scale | /usr/bin/tail -1 > $dir/svm-arg`;
		$_ = `/bin/cat $dir/svm-arg`;
		/^(\S+) (\S+) \S+/ or exit;
		`$bin/svm-train -b 1 -c $1 -g $2 $dir/tr.svm.scale $dir/tr.svm.scale.model`;
		print "$1 $2\n";
	} elsif ( 'regression' eq $type ) {
		`$bin/gridregression.py -svmtrain $bin/svm-train -log2c 0,4,2 -log2g -4,0,2 -log2p -4,0,2 -v 5 -out $dir/tr.svm.scale.out $dir/tr.svm.scale | /usr/bin/tail -1 > $dir/svm-arg`;
		$_ = `/bin/cat $dir/svm-arg`;
		/^(\S+) (\S+) (\S+)/ or exit;
		`$bin/svm-train -s 3 -c $1 -g $2 -p $3 $dir/tr.svm.scale $dir/tr.svm.scale.model`;
		print "$1 $2 $3\n";
	}
} elsif ( 'rf' eq $classifier ) {
	&svm2rf( "$dir/tr.svm.scale", "$dir/tr.rf", "$dir/tr.scale" );
	$type = 'classification' eq $type ? 'classify' : 'regress';
	`/bin/cat $bin/rf-grid.r | /usr/bin/R --slave --vanilla --args $type $dir/tr.rf > $dir/rf-arg`;
	$_ = `/bin/cat $dir/rf-arg`;
	/^(\S+) (\S+)/ or exit;
	`/bin/cat $bin/rf-train.r | /usr/bin/R --slave --vanilla --args $type $1 $2 $dir/tr.rf $dir/tr.rf.model`;
	print `/bin/cat $dir/rf-arg`;
}

sub make_cmd {
	my $fn = shift;
	$fn =~ /\.jar$/ and return "/usr/bin/java -jar $dir/$fn";
	return "$dir/$fn";
}

# vi:nowrap:sw=4:ts=4
