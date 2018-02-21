# cat rf-predict | R --slave --vanilla --args TYPE MODEL TE

library(randomForest)
set.seed(0)

type <- commandArgs(TRUE)[1]
load(commandArgs(TRUE)[2])
csv <- read.csv( commandArgs(TRUE)[3], header=FALSE ); te.x <- csv[,2:ncol(csv)]

if ( 'classify' == type ) {
	response <- predict( rf, te.x, type='response' )
	prob <- predict( rf, te.x, type='prob' )
	write.table( cbind( matrix(response), prob ), col.names=FALSE, row.names=FALSE )
} else {
	response <- predict( rf, te.x, type='response' )
	write.table( response, col.names=FALSE, row.names=FALSE )
}

# vi:nowrap:sw=4:ts=4
