# cat rf.train.r | R --slave --vanilla --args TYPE NTREE MTRY TR MODEL IMP_PLOT

library(randomForest)

type <- commandArgs(TRUE)[1]
ntree <- as.integer(commandArgs(TRUE)[2])
mtry <- as.integer(commandArgs(TRUE)[3])
#model <- commandArgs(TRUE)[5]
imp <- commandArgs(TRUE)[5]
seed = 0; set.seed(seed)

# load data
csv <- read.csv( commandArgs(TRUE)[4], header=FALSE ); tr.x <- csv[,2:ncol(csv)]
if ( 'classify' == type ) {
	tr.y <- factor(csv[,1])
} else {
	tr.y <- csv[,1]
}

rf <- randomForest( tr.y ~ ., data=tr.x, ntree=ntree, mtry=mtry, importance=TRUE, na.action=na.omit )
#save( rf, file=model )

png( imp, height = 15*nrow(rf$importance)+150 )
varImpPlot( rf, n.var=nrow(rf$importance) )

if ( 'classify' == type ) {
	importance(rf)[,-2:-1]
} else {
	importance(rf)
}

# vi:nowrap:sw=4:ts=4
