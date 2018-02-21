# cat rf.r | R --slave --vanilla --args NTREE MTRY TR PNG

library(randomForest)

set.seed(0)

ntree = as.integer(commandArgs(TRUE)[1])
mtry = as.integer(commandArgs(TRUE)[1])

# load data
csv <- read.csv( commandArgs(TRUE)[3], header=FALSE ); tr.y <- csv[,1]; tr.x <- csv[,2:ncol(csv)]

################################################################################
# random forest

rf = randomForest( tr.y ~ ., data=tr.x, ntree=ntree, mtry=mtry, importance=TRUE, na.action=na.omit )

png( commandArgs(TRUE)[4], height = 40*nrow(rf$importance) )
varImpPlot(rf,n.var=nrow(rf$importance))
dev.off()

importance(rf)

# vi:nowrap:sw=4:ts=4
