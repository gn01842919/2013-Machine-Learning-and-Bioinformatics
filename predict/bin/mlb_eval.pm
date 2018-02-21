#!/usr/bin/perl -w

################################################################################
# Common subs in mlb-eval
################################################################################

use strict;
use Date::Calc;

sub buy {
	my $cfg = shift;
	my $own = shift;
	my $log = shift;

	for my $c (@{$cfg->{se}}){
		my $p = $cfg->{price}{$c->{code}}{$cfg->{date}};
		$p->{'close'} or next;
		$p->{low} or $p->{low} = $p->{'close'};
		$p->{high} or $p->{high} = $p->{'close'};
		$c->{vol} or next;

		$c->{price} or $c->{price} = $p->{low};
		$c->{price} >= $p->{low} or next;

		(($own->{budget} - $c->{vol}*$c->{price}) >= 0 )or next;
		$own->{budget} -= $c->{vol}*$c->{price};
		
		my %log = ( code => $c->{code}, date => $cfg->{date}, price => $c->{price},  vol => $c->{vol}, life=>0);

		push @{ $own->{stock}{$c->{code}} }, {%log,target=>$c->{target},maxlife=>$c->{life}};
		$log{act} = 'buy'; push @$log , \%log;
	}
}
sub check_channel {
	my $dir = shift;
	my $err = shift;
	my $cgi = shift;
	my %check = map { $_ => 1 } @_;
	my $ch = &load_json("$dir/.info");
	$ch or $err->('Broken channel!  Please reset it.') and return;

	defined $cgi and defined $cgi->param('ch') and $ch->{ch} = $cgi->param('ch');
=f
	for ( qw/select feature buy sell/ ) {
		defined $check{$_} or next;
		defined $ch->{$_} or $err->("Undefined $_ program!") and return;
		-f "$dir/$ch->{$_}" and -x "$dir/$ch->{$_}" or $err->("Broken $_ program!") and return;
	}
	-f "$dir/$ch->{select}" or $err->("Broken JSON file") and return;
=cut
	-f "$dir/$ch->{se}" or $err->("Broken JSON file") and return;

	if ( defined $check{tool} ) {
		defined $cgi and defined $cgi->param('tool') and $ch->{tool} = $cgi->param('tool');
		defined $ch->{tool} or $err->('Undefined tool!') and return;
	}
	if ( defined $check{type} ){
		defined $cgi and defined $cgi->param('type') and $ch->{type} = $cgi->param('type');
		defined $ch->{type} and '' ne $ch->{type}
			or $ch->{type} = 'classification';
#			or $err->('Undefined type!');
	}
	if ( defined $check{model} ) {
		if ( 'rvkde' eq $ch->{tool} ) {
			@_ = qw/a b ks kt/;
		} elsif ( 'libsvm' eq $ch->{tool} ) {
			if ( 'classification' eq $ch->{type} ) {
				@_ = qw/c g/;
			} elsif ( 'regression' eq $ch->{type} ){
				@_ = qw/c g p/;
			} else { $err->("Invalid type ($ch->{type})!") and return; }
		} elsif ( 'rf' eq $ch->{tool} ) {
			defined $ch->{ntree} or $ch->{ntree} = 10;
			defined $ch->{mtry} or $ch->{mtry} = 3;
			@_ = qw/ntree mtry/;
		} else { $err->('Invalid tool!') and return; }
		for (@_) {
			defined $cgi and defined $cgi->param($_) and $ch->{$_} = $cgi->param($_);
			defined $ch->{$_} and '' ne $ch->{$_}
				or $err->("Missing parameter ($_)!") and return;
		}
	}
	if ( defined $check{te} ){
		defined $cgi and defined $cgi->param('te') and $ch->{te} = $cgi->param('te');
		defined $ch->{te} or $err->('Undefined test set!') and return;
	}
	return $ch;
}

sub check_yaml {
	my $fn = shift;
	-e $fn or return 0;
	$_ = `/bin/cat $fn`;	
	/^---/s or return 0;
	return 1;
}

sub date_diff {
	my $s = "$_[1] $_[0]";
	my @d = $s =~ /^(\d+)-(\d+)-(\d+) (\d+)-(\d+)-(\d+)$/ or return 0;
	return &Date::Calc::Delta_Days(@d);
}

sub date_range {
	my $start = shift;
	my $end = shift;
	my $stock = shift;

	my @s = $start =~ /^(\d+)-(\d+)-(\d+)$/ or die;
	my @e = $end   =~ /^(\d+)-(\d+)-(\d+)$/ or die;

	my @date;
	for ( `/bin/cat $stock` ) {
		/^((\d+)-(\d+)-(\d+)),/ or next;

		&Date::Calc::Delta_Days( $2, $3, $4, @e ) < 0 and next;
		&Date::Calc::Delta_Days( $2, $3, $4, @s ) > 0 and last;
		unshift @date, $1;
	}

	return \@date;
}

sub exec {
	my $init = shift;
	my $log = shift;
	my %own = ( budget => $init );
	my %date;
	my %budget;
	for (@$log) {
		if ( 'buy' eq $_->{act} ) {
			$own{budget} -= $_->{price} * $_->{vol};
			my %log = %$_; delete $log{act};
			push @{ $own{stock}{ $_->{code} } }, \%log;
		} elsif ( 'sell' eq $_->{act} ) {
			$own{budget} += $_->{price} * $_->{vol};
			my $ind_stock = $own{stock}{ $_->{code} }[ $_->{i} ];
			if ( $ind_stock->{price} != $_->{buy_price} ) { die; } #! fix log
			$own{stock}{ $_->{code} }[ $_->{i} ]{sell_date}  = $_->{date};
			$own{stock}{ $_->{code} }[ $_->{i} ]{sell_price} = $_->{price};
			$own{stock}{ $_->{code} }[ $_->{i} ]{sold} = 1;
		} elsif ( 'reset' eq $_->{act} ) {
			%own = ( budget => $init );
		}
	}
	return \%own;
}

sub fix_log {
	my $stock_dir = shift;
	my $log = shift;
	my %price;
	for my $s (@$log) {
		my $price = &price( $stock_dir, $s->{code}, $s->{date}, \%price ) or die;
		if ( 'buy' eq $s->{act} ) {
			$s->{price} > $price->{high} and $s->{price} = $price->{'open'};
		} elsif ( 'sell' eq $s->{act} ) {
			$s->{price} < $price->{low}  and $s->{price} = $price->{'open'};
		}
	}
}
sub get_stock_code {
	my $cfg = shift;
	my $dbh = shift;
	if ( $cfg->{read_stock} eq 'mysql' ){
		@_ = ();
		my $row = $dbh->prepare("select code from $cfg->{code_table}");
		$row->execute();
		push @_, grep { $_ = $_->[0] } @{$row->fetchall_arrayref()};
		if ($#_ < 0){
			@_ = &ini_stock;
			#for (@_){ $dbh->do("insert into $cfg->{code_table} (code) values (\'$_\')"); }
		}
	}else{
		`/bin/mkdir -p $cfg->{dir}/$cfg->{stock}`;
		@_ = `/bin/ls $cfg->{dir}/$cfg->{stock}`;
		scalar @_ or @_ = &ini_stock;
	}
	return @_;
}
sub load_all_price {
	my $cfg = shift;
	my $dbh = shift;
	my $from = shift;
	my $to = shift;
	my $price;
	if($cfg->{read_stock} eq 'mysql'){
		my $row = $dbh->prepare("select * from $cfg->{stock_table} where date >= \'".sprintf("%04d-%02d-%02d",@$from)."\' and date <= \'".sprintf("%04d-%02d-%02d",@$to)."\' order by date desc");
		$row->execute();
		while(my $ref =$row->fetchrow_hashref){
			if( 'twii' eq $ref->{code} ){
				$price->{$ref->{code}}{$ref->{date}} = { 'close' => $ref->{'close'} };
				return $price->{$ref->{code}}{$ref->{date}};
			}
			$price->{$ref->{code}}{$ref->{date}} = { 'open' => $ref->{'open'}, high => $ref->{high}, low => $ref->{low}, 'close' => $ref->{'close'}, vol => $ref->{vol}, adj_close => $ref->{adj_close} };
			!defined $ref->{'close'} and $price->{$ref->{code}}{$ref->{date}}{'close'} = $ref->{adj_close};
			!defined $ref->{'open'} and $price->{$ref->{code}}{$ref->{date}}{'open'}  = $price->{$ref->{code}}{$ref->{date}}{'close'};
			!defined $ref->{high} and $price->{$ref->{code}}{$ref->{date}}{high}    = $price->{$ref->{code}}{$ref->{date}}{'close'};
			!defined $ref->{low} and $price->{$ref->{code}}{$ref->{date}}{low}     = $price->{$ref->{code}}{$ref->{date}}{'close'};
		}

	}else{
		my @code = &get_stock_code($cfg,$dbh);
		my $from = sprintf("%04d-%02d-%02d",@$from);
		my $to = sprintf("%04d-%02d-%02d",@$to);
		for my $code ( @code ){
			$_ =  `/bin/grep -A500 "^$to," $cfg->{dir}/$cfg->{stock}/$code | /bin/grep -B500 "^$from," | /usr/bin/awk '{FS=","} \$5 > 0'`;
			chomp $_; my @stock_info = split $_;

			for my $stock_info (@stock_info) {
				if ( 'twii' eq $code ) {
					$stock_info =~ /(\S+?),(\S+?),\S+?,\S+?,\S+?(?:,|$)/ or return;
					$price->{$code}{$1} = { 'close' => $2 };
					return $price->{$code}{$1};
				}
				# Date,Open,High,Low,Close,Volume,Adj Close
				$stock_info =~ /(\S+?),(\S+?),(\S+?),(\S+?),(\S+?),(\S+?),(\S+?)(?:,|$)/ or return;
				$price->{$code}{$1} = { 'open' => $2, high => $3, low => $4, 'close' => $5, vol => $6 , adj_close => $7 };
				'--' eq $5 and $price->{$code}{$1}{'close'} = $7;
				($7 eq '--' and $5 eq '--') and $price->{$code}{$1}{'close'} = 0;
				'--' eq $2 and $price->{$code}{$1}{'open'}  = $price->{$code}{$1}{'close'};
				'--' eq $3 and $price->{$code}{$1}{high}    = $price->{$code}{$1}{'close'};
				'--' eq $4 and $price->{$code}{$1}{low}     = $price->{$code}{$1}{'close'};
			}
		}
	}
	return $price;
}
sub load_json {
	my $fn = shift;
	my $text;
	-e $fn or return;
	open FH,$fn;
	$text = <FH>;
	close FH;

	my $ret;
	eval{ $ret = decode_json($text); };
	return $ret;
}
sub make_cmd {
	my $fn = shift;
	$fn =~ /\.jar$/ and return "/usr/bin/java -cp lhorok/lib -jar $fn";
	return $fn;
}

sub model {
	my $cfg = shift;
	my $dir = $cfg->{dir};

	# select
	`$cfg->{select} train $cfg->{'stock-dir'}/ $dir/ > $dir/tr.select.out`;
	-s "$dir/tr.select.out" or $cfg->{'err'}->('No output from select program!') and return;

	# feature
	`$cfg->{feature} train $dir/tr.select.out $dir/tr.info $dir/tr.svm $dir/`; 
	-s "$dir/tr.svm" or $cfg->{'err'}->('No output from feature program!') and return;
	`$cfg->{'svm-scale'} -s $dir/tr.scale $dir/tr.svm > $dir/tr.svm.scale`;
	-s "$dir/tr.svm.scale" or $cfg->{'err'}->('No output from svm-scale!') and return;

	# model
	if ( 'rvkde' eq $cfg->{ch}{tool} ) {
	} elsif ( 'libsvm' eq $cfg->{ch}{tool} ) {
		my $args = 'classification' eq $cfg->{ch}{type} ? '-b 1' : "-s 3 -p $cfg->{ch}{p}";
		`$cfg->{'svm-train'} $args -c $cfg->{ch}{c} -g $cfg->{ch}{g} $dir/tr.svm.scale $dir/tr.svm.scale.model`;
		-s "$dir/tr.svm.scale.model" or $cfg->{'err'}->('No output from svm-tram!') and return;
	} elsif ( 'rf' eq $cfg->{ch}{tool} ) {
		&svm2rf( "$dir/tr.svm.scale", "$dir/tr.rf", "$dir/tr.scale" );
		my $type = 'classification' eq $cfg->{ch}{type} ? 'classify' : 'regress';
    	`/bin/cat $cfg->{'rf-train.r'} | /usr/bin/R --slave --vanilla --args $type $cfg->{ch}{ntree} $cfg->{ch}{mtry} $dir/tr.rf $dir/tr.rf.model /dev/null`;
		-s "$dir/tr.rf.model" or $cfg->{'err'}->('No output from rf-train.r!') and return;
	}
}
sub normalize_date {
	my $arr = shift;
	!(ref $arr and $arr =~ /ARRAY/) and return;
	for my $l ( @{$arr} ){
		!(ref $l and $l =~ /HASH/ and $l->{data}) and return;
		@{$l->{data}} = map { $_ = [ str2time($_->[0].' UTC')*1000, $_->[1] ] } @{$l->{data}};
	}
}
sub price {
	my $dir = shift;
	my $code = shift;
	my $date = shift;
	my $price = shift;
	defined $price->{$code}{$date} and return $price->{$code}{$date};
	my $stock_info = `/bin/grep "^$date," $dir/$code`;
	if ( 'twii' eq $code ) {
		$stock_info =~ /\S+?,(\S+?),\S+?,\S+?,\S+?$/ or return;
		$price->{$code}{$date} = { 'close' => $1 };
		return $price->{$code}{$date};
	}

	# Date,Open,High,Low,Close,Volume,Adj Close
	$stock_info =~ /\S+?,(\S+?),(\S+?),(\S+?),(\S+?),(\S+?),(\S+?)$/ or return;
	$price->{$code}{$date} = { 'open' => $1, high => $2, low => $3, 'close' => $4, vol => $5 };
	'--' eq $4 and $price->{$code}{$date}{'close'} = $6;
	'--' eq $1 and $price->{$code}{$date}{'open'}  = $price->{$code}{$date}{'close'};
	'--' eq $2 and $price->{$code}{$date}{high}    = $price->{$code}{$date}{'close'};
	'--' eq $3 and $price->{$code}{$date}{low}     = $price->{$code}{$date}{'close'};
	return $price->{$code}{$date};
}

sub save_json {
	my $fn = shift;
	my $data = shift;
	#open FH,">$fn" or &errmsg('Fail to write file');
	open FH,">$fn" or return;
	print FH encode_json($data);
	close FH;
}
sub sell {
	my $cfg = shift;
	my $own = shift;
	my $log = shift;
	for my $code ( keys %{ $own->{stock} } ) {
		for my $i ( 0 .. $#{ $own->{stock}{$code} } ) {
			my $o = $own->{stock}{$code}[$i];
			$o->{sold} and next;
			$o->{life}++;
			my $p = $cfg->{price}{$o->{code}}{$cfg->{date}}{'close'};
			$p or next;

			if($o->{life} < $o->{maxlife}){
				if(my ($rate) = $o->{target} =~ /(\d+(?:\.\d+)?)%$/){
					$rate <= ($p - $o->{price})/$p or next; 
				}else{
					$o->{target} <= $p or next;
				}
			}

			$own->{budget} += $p * $o->{vol};
			$o->{sold} = 1;
			push @$log, { act => 'sell', 'buy_price' => $o->{price}, code => $o->{code}, date => $cfg->{date}, price => $p, vol => $o->{vol} };
		}
		for my $i ( 0 .. $#{ $own->{stock}{$code} } ) {
			$own->{stock}{$code}[$i]{sold} and splice @{$own->{stock}{$code}}, $i, 1 and $i--;
		}
	}
}
sub _sell {
	my $cfg = shift;
	my $own = shift;
	my $log = shift;

	my $dir = $cfg->{dir};

	my $n = 0; # current own stocks (date-dependent)
	open FH, ">$dir/sell.in" or $cfg->{'err'}->('Write file (sell.in) error!') and return;
	print FH "#Stock,Buy Date,Buy Price,Volume;ML;User\n";
	for my $code ( keys %{ $own->{stock} } ) {
		for ( @{ $own->{stock}{$code} } ) {
			$_->{sold} and next;
			++$n;
			print FH "$code,$_->{date},$_->{price},$_->{vol};$_->{info}\n";
		}
	}
	close FH;

	`$cfg->{sell} $dir/sell.in $cfg->{date} $dir/ > $dir/sell.out`; # 'sell.out' to debug
	my @sell = `/bin/grep -v '^#' $dir/sell.out`;
	scalar @sell == $n or $cfg->{'err'}->('Incorrect output of sell program!') and return;
	my $iSell = 0;
	for my $code ( keys %{ $own->{stock} } ) {
		my $price = &price( $cfg->{'stock-dir'}, $code, $cfg->{date}, $cfg->{price} )
			or $cfg->{'err'}->("Missing stock ($code) price at $cfg->{date}!") and next;
		defined $price->{high} or $cfg->{'err'}->("Missing stock ($code) high price at $cfg->{date}!") and next;
		for my $i ( 0 .. $#{ $own->{stock}{$code} } ) {
			$_ = $own->{stock}{$code}[$i];
			$_->{sold} and next;
			# Condition,Volume
			my ( $cmp, $value, $vol ) = $sell[$iSell++] =~ /^(>=?)([\-\d\.]+),(\d+000)$/ or $cfg->{'err'}->('Incorrect output of sell program!') and return;
			( 0 == $value or $value < $price->{low} ) and $value = $price->{'open'};
			$vol = $_->{vol}; # ignore $vol
			$price->{high} >= $value or next; # ignore $cmp

			# actual sell
			$own->{budget} += $value * $vol;
			$_->{sold} = 1;
			push @$log, { act => 'sell', buy_price => $_->{price}, code => $code, date => $cfg->{date}, i => $i, price => $value, vol => $vol };
		}
	}
}

sub stat {
	my $cfg = shift;
	my $log = shift;
	my %own = ( cash => $cfg->{init}, last_assets => $cfg->{init}, turnover => 0, profit => 0, nBuy => 0, nSell => 0 );
	my %stat;
	my %zero;

	my %l; for ( @$log ) { push @{ $l{ $_->{date} } }, $_; } # log by date
	for my $d ( @{ $cfg->{date} } ) {
		for ( @{ $l{$d} } ) {
			# log table
			my $price = sprintf '%.2f', $_->{price};
			my $buy_price = $_->{buy_price} ? sprintf '%.2f', $_->{buy_price} : '-';
			push @{ $stat{'log'} }, [ $_->{date}, $_->{act}, $_->{code}, $price, $_->{vol}, $buy_price ];

			# own stocks
			my $cost = $_->{price} * $_->{vol};
			$own{stock}{ $_->{code} } or $own{stock}{ $_->{code} } = {};
			my $own_stock = $own{stock}{ $_->{code} };
			if ( 'buy' eq $_->{act} ) {
				++$own{nBuy};
				$own{capital}  += $cost;
				$own{cash}     -= $cost;
				$own{turnover} += $cost;
				$own{vol}      += $_->{vol};
				$own_stock->{cost} += $cost;
				$own_stock->{vol}  += $_->{vol};
				$own_stock->{buy_price} = $_->{price};
				my %ind = %$_; delete $ind{act};
				push @{ $own_stock->{ind} }, \%ind;
			} elsif ( 'sell' eq $_->{act} ) {
				++$own{nSell};
				$own{cash}     += $cost;
				$own{turnover} += $cost;
				$own_stock->{cost} -= $cost;
				$own_stock->{vol}  -= $_->{vol};
=f
				my $ind_stock = $own_stock->{ind}[ $_->{i} ];
				$ind_stock->{sell_date}  = $_->{date};
				$ind_stock->{sell_price} = $_->{price};
				$ind_stock->{sold}       = 1;
=cut
			} elsif ( 'reset' eq $_->{act} ) {
				%own = ( cash => $cfg->{init} );
			}
		}

		# charts
		$own{assets} = $own{cash};
		$own{nAct}   = $own{nBuy} + $own{nSell};
		for my $code ( keys %{ $own{stock} } ) {
			
			#$_ = &price( $cfg->{'stock-dir'}, $code, $d, $cfg->{price} )
			#	or $cfg->{'err'}->("Missing stock ($code) price at $d!") and next;
			$_ = $cfg->{price}{$code}{$d} or next;
			$own{assets} += $_->{'close'} * $own{stock}{$code}{vol};
		}
		
		$own{profit} = $own{assets} - $cfg->{init};
		$own{last_assets} = $own{assets};
		#$d eq $cfg->{'zero-date'} and map { $zero{$_} = $own{$_} } qw/assets capital cash nAct profit turnover vol/;
		for ( qw/assets cash nAct turnover/ ) {
			push @{ $stat{$_} }, [ $d, $own{$_} ];
			#	$d ge $cfg->{'zero-date'}
			#	and push @{ $stat{"lw-$_"} }, [ $d, $own{$_} - $zero{$_} ];
		}

		$own{'profit-over-capital'} = $own{capital} ? $own{profit} / $own{capital} : 0;
		$own{'profit-over-vol'}     = $own{vol}     ? $own{profit} / $own{vol}     : 0;
=f
		if ( $d ge $cfg->{'zero-date'} ) {
			$own{'lw-profit-over-capital'} = ( $own{capital} - $zero{capital} ) ? ( $own{profit} - $zero{profit} ) / ( $own{capital} - $zero{capital} ) : 0;
			$own{'lw-profit-over-vol'}     = ( $own{vol}     - $zero{vol}     ) ? ( $own{profit} - $zero{profit} ) / ( $own{vol}     - $zero{vol}     ) : 0;
		}
=cut
		for ( qw/profit-over-capital profit-over-vol/ ) {
			push @{ $stat{$_} }, [ $d, $own{$_} ];
#			$d ge $cfg->{'zero-date'}
#				and push @{ $stat{"lw-$_"} }, [ $d, $own{"lw-$_"} ];
		}
		defined $cfg->{code} and $own{stock}{ $cfg->{code} }{vol}
			and push @{ $stat{ $cfg->{code} }{avg} }, [ $d, $own{stock}{ $cfg->{code} }{cost} / $own{stock}{ $cfg->{code} }{vol} ];
	}

	# ind table
	my $last_date = $cfg->{date}[-1];
	for my $code ( keys %{ $own{stock} } ) {
		for ( @{ $own{stock}{$code}{ind} } ) {
			my ( $date, $price, $days ); # sell date, sell price, own days
			if ( $_->{sold} ) {
				$date  = $_->{sell_date};
				$price = $_->{sell_price};
				$days  = &date_diff( $_->{sell_date}, $_->{date} );
			} else {
				$date  = 'not yet';
				$price = $cfg->{price}{$code}{$last_date}{'close'};
				$days  = &date_diff( $last_date, $_->{date} );
			}
			my $profit = sprintf( "%+d", ( $price - $_->{price} ) * $_->{vol} ); $profit =~ s/^\+0$/0/;
			my $class = '';
			$profit > 0 and $class .= ' gain';
			$profit < 0 and $class .= ' loss';
			my ( $pred, $info ) = ( '-', '-' );
			#	$_->{info} =~ /^([^,;]+).*?;(.*)$/ and ( $pred, $info ) = ( $1, $2 );
			push @{ $stat{ind} }, {
				DT_RowClass => $class,
				code => $code,
				'buy-date' => $_->{date},
				'buy-price' => sprintf( '%.2f', $_->{price} ),
				'sell-date' => $date,
				'sell-price' => sprintf( '%.2f', $price ),
				volume => $_->{vol},
				profit => $profit,
				'_%profit' => sprintf( '%.1f', 100*($price-$_->{price})/$_->{price} ),
				#	'pred' => sprintf( '%.3f', $pred ),
				#'user-info' => "<div title='$info'>$info</div>",
				days => $days,
			};
		}
	}

	return \%stat;
}

sub svm2rf {
	my $svm = shift;
	my $rf = shift;
	my $scale = shift;
	$_ = `/usr/bin/wc -l $scale`; /^(\d+)/; my $d = $1 - 2;
	open FHS, $svm or die;
	open FHR, ">$rf" or die;
	while (<FHS>) {
		chomp;
		@_ = split / /;
		print FHR shift @_;
		my %f; # feature
		for (@_) {
			/^(\d+):(\S+)$/ or die;
			$f{$1} = $2;
		}
		for ( 1 .. $d ) { print FHR ',', $f{$_} || 0; }
		print FHR "\n";
	}
	close FHS;
	close FHR;
}

sub twii {
	my $cfg = shift;
	my $start;
	my %twii;
	my $zero;

	my $date = &date_range( $cfg->{'start-date'}, $cfg->{'end-date'}, "$cfg->{'stock-dir'}/twii" );

	for my $d ( @$date ) {
		$_ = &price( $cfg->{'stock-dir'}, 'twii', $d, $cfg->{price} )
			or $cfg->{'err'}->("Missing twii at $d!") and next;
		$d eq $cfg->{'start-date'} and $start = $_->{'close'};
		$d eq $cfg->{'zero-date'} and $zero = $_->{'close'};
		push @{ $twii{twii} }, [ $d, $_->{'close'} / $start * $cfg->{init} ];
		$d ge $cfg->{'zero-date'}
			and push @{ $twii{'lw-twii'} }, [ $d, ( $_->{'close'} - $zero ) / $start * $cfg->{init} ];
	}
	return \%twii;
}

1;

# vi:nowrap:sw=4:ts=4
