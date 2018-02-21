# cat rf-grid.r | R --slave --vanilla --args TYPE TR

library(randomForest)
set.seed(0)

type <- commandArgs(TRUE)[1]

# load data
csv <- read.csv( commandArgs(TRUE)[2], header=FALSE ); tr.x <- csv[,2:ncol(csv)]
if ( 'classify' == type ) {
	tr.y <- factor(csv[,1])
} else {
	tr.y <- csv[,1]
}

best_acc   <- 0
best_mae   <- 1e10 # a dummy high value
best_mtry  <- 3
best_ntree <- 5
if ( 'classify' == type ) {
	for ( ntree in 5:10 ) {
		for ( mtry in 1:3 ) {
			rf <- randomForest( tr.y ~ ., data = tr.x, ntree=ntree, mtry=mtry, na.action=na.omit );
			rf$predicted[ is.na(rf$predicted) ] = 0;
			acc = sum( rf$predicted == rf$y ) / length(rf$y);
			if ( acc > best_acc ) {
				best_acc <- acc;
				best_mtry <- mtry;
				best_ntree <- ntree;
			}
		}
	}
} else {
	for ( ntree in 5:10 ) {
		for ( mtry in 1:3 ) {
			rf <- randomForest( tr.y ~ ., data = tr.x, ntree=ntree, mtry=mtry, na.action=na.omit );
			rf$predicted[ is.na(rf$predicted) ] = 0;
			mae = mean( abs( rf$predicted - rf$y ) )
			if ( mae < best_mae ) {
				best_mae <- mae;
				best_mtry <- mtry;
				best_ntree <- ntree;
			}
		}
	}
}
cat( sprintf( "%d %d\n", best_ntree, best_mtry ) )

# vi:nowrap:sw=4:ts=4
